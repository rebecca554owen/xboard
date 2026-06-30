<?php

namespace Plugin\AutoDeleteInactiveUsers;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const LOCK_KEY = 'plugin:auto_delete_inactive_users:lock';
    private const SCAN_CURSOR_KEY_PREFIX = 'plugin:auto_delete_inactive_users:scan_cursor:';
    private const LOCK_TTL = 300;

    public function boot(): void
    {
    }

    public function schedule(Schedule $schedule): void
    {
        $frequency = $this->getConfig('schedule_frequency', 'daily');

        $task = $schedule->call(function (): void {
            $this->deleteInactiveUsers();
        });

        switch ($frequency) {
            case 'minutely':
                $task->everyMinute();
                break;
            case 'hourly':
                $task->hourly();
                break;
            case 'daily':
            default:
                $task->dailyAt('00:00');
                break;
        }

        $task->name('auto_delete_inactive_users')->onOneServer();
    }

    protected function deleteInactiveUsers(): void
    {
        if (!Cache::add(self::LOCK_KEY, 1, self::LOCK_TTL)) {
            Log::info('检测到任务已在运行，跳过本轮执行');

            return;
        }

        $days = max(1, (int) $this->getConfig('delete_days', 7));
        $batchSize = max(1, (int) $this->getConfig('batch_size', 100));
        $maxBatchesPerRun = max(1, (int) $this->getConfig('max_batches_per_run', 10));
        $enableAutoDelete = (bool) $this->getConfig('enable_auto_delete', false);
        $deleteExpiredAfterDays = max(0, (int) $this->getConfig('delete_expired_users_after_days', 0));

        try {
            $orphanTicketMessageCount = 0;
            $invalidUserCount = 0;
            $expiredUserCount = 0;

            if (!$enableAutoDelete) {
                $orphanTicketMessageCount = $this->countOrphanTicketMessages();
                $invalidUserCount = $this->countInvalidUsers($days);
                $expiredUserCount = $this->countExpiredUsers($days, $deleteExpiredAfterDays);
            }

            if ($enableAutoDelete) {
                $orphanTicketMessageCount = $this->deleteOrphanTicketMessages($batchSize);
                $totalDeleted = 0;
                $retryCount = 0;
                $maxRetries = 3;
                $processedBatches = 0;
                $scanCursorKey = $this->getScanCursorKey($deleteExpiredAfterDays);
                $scanCursorId = (int) Cache::get($scanCursorKey, 0);

                while ($processedBatches < $maxBatchesPerRun) {
                    $candidates = $this->queryCandidateUsers($days, $batchSize, $deleteExpiredAfterDays, $scanCursorId);
                    if ($candidates->isEmpty()) {
                        if ($scanCursorId > 0) {
                            Cache::forget($scanCursorKey);
                            Log::info('扫描游标已到末尾，下轮将从头检查', [
                                'mode' => $deleteExpiredAfterDays > 0 ? 'expired' : 'invalid',
                                'last_cursor_id' => $scanCursorId,
                            ]);
                        }
                        break;
                    }

                    $batchDeleted = $this->deleteUsers($candidates);
                    $totalDeleted += $batchDeleted;

                    if ($batchDeleted === 0) {
                        ++$retryCount;
                        if ($retryCount >= $maxRetries) {
                            Log::warning('达到最大重试次数，停止删除', [
                                'total_deleted' => $totalDeleted,
                                'retry_count' => $retryCount,
                            ]);
                            break;
                        }
                    } else {
                        $retryCount = 0;
                        $scanCursorId = (int) $candidates->last()->id;
                        Cache::forever($scanCursorKey, $scanCursorId);
                    }

                    ++$processedBatches;
                }

                if ($processedBatches >= $maxBatchesPerRun) {
                    Log::info('达到单轮最大批次数，等待下次调度继续', [
                        'processed_batches' => $processedBatches,
                        'max_batches_per_run' => $maxBatchesPerRun,
                        'next_cursor_id' => $scanCursorId,
                    ]);
                }

                if ($deleteExpiredAfterDays > 0) {
                    $expiredUserCount = $totalDeleted;
                    $invalidUserCount = 0;
                } else {
                    $invalidUserCount = $totalDeleted;
                    $expiredUserCount = 0;
                }
            }

            $totalDeleted = $invalidUserCount + $expiredUserCount;

            if (!$enableAutoDelete && $orphanTicketMessageCount > 0) {
                Log::warning(sprintf('[试运行]旧版删除遗留的孤儿工单消息%d条', $orphanTicketMessageCount));
            } elseif ($enableAutoDelete && $orphanTicketMessageCount > 0) {
                Log::info(sprintf('完成：清理旧版删除遗留的孤儿工单消息 %d 条', $orphanTicketMessageCount));
            }

            if (!$enableAutoDelete && $totalDeleted > 0) {
                $template = $deleteExpiredAfterDays > 0
                    ? '[试运行]注册%d天以上无效用户%d个，过期时间大于%d天用户%d个'
                    : '[试运行]注册%d天以上无效用户%d个，所有过期用户%d个';

                $params = $deleteExpiredAfterDays > 0
                    ? [$days, $invalidUserCount, $deleteExpiredAfterDays, $expiredUserCount]
                    : [$days, $invalidUserCount, $expiredUserCount];

                Log::warning(vsprintf($template, $params));
            } elseif ($enableAutoDelete && $totalDeleted > 0) {
                Log::info(sprintf('完成：共删除 %d 个用户', $totalDeleted));
            }
        } catch (\Throwable $exception) {
            Log::error('执行过程中发生异常', [
                'error' => $exception->getMessage(),
            ]);
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /**
     * @return Collection<int, User>
     */
    protected function queryCandidateUsers(int $days, int $batchSize, int $deleteExpiredAfterDays = 0, int $afterId = 0): Collection
    {
        // 性能优化：使用 LEFT JOIN 替代 whereNotIn 子查询
        // 优化前：whereNotIn 需要执行子查询，随着用户表增长性能下降
        // 优化后：LEFT JOIN 可以利用索引，查询效率更高
        $query = $this->buildBaseQuery();

        if ($deleteExpiredAfterDays > 0) {
            // 删除过期用户：过期时间大于指定天数
            $query->where('v2_user.expired_at', '>', 0)
                  ->where('v2_user.expired_at', '<', time() - ($deleteExpiredAfterDays * 86400));
        } else {
            // 删除无效用户：未订阅、无流量、未过期且从未邀请过他人
            // 性能优化：LEFT JOIN 查找从未作为邀请人的用户
            // 所需索引：CREATE INDEX idx_invited_user ON users(invite_user_id, id);
            $query->leftJoin('v2_user as invited', 'invited.invite_user_id', 'v2_user.id')
                  ->whereNull('invited.id')  // 从未邀请过他人
                  ->whereNull('v2_user.plan_id')      // 未订阅
                  ->where('v2_user.transfer_enable', 0)  // 无流量
                  ->where(function ($query) {
                      $query->where('v2_user.expired_at', 0)
                            ->orWhereNull('v2_user.expired_at');
                  });   // 兼容旧版 expired_at=0 与新版 expired_at=NULL 的未过期无效用户
        }

        if ($afterId > 0) {
            $query->where('v2_user.id', '>', $afterId);
        }

        // 性能优化：添加时间过滤，利用 created_at 索引
        // 所需索引：CREATE INDEX idx_cleanup_basic ON users(is_admin, balance, commission_balance, created_at);
        // 重要：使用 select 确保只返回主表字段，避免 LEFT JOIN 导致的 NULL 值污染
        return $query->where('v2_user.created_at', '<', time() - ($days * 86400))
                     ->orderBy('v2_user.id')
                     ->select(['v2_user.id', 'v2_user.email', 'v2_user.created_at'])
                     ->limit($batchSize)
                     ->get();
    }

    /**
     * 统计无效用户数量
     *
     * 性能优化：使用 LEFT JOIN 替代 whereNotIn 子查询
     * 优化前：whereNotIn 需要执行子查询，随着用户表增长性能下降
     * 优化后：LEFT JOIN 可以利用索引，查询效率更高
     *
     * 所需索引：
     * - CREATE INDEX idx_invited_user ON users(invite_user_id, id);
     * - CREATE INDEX idx_cleanup_basic ON users(is_admin, balance, commission_balance, created_at);
     * - CREATE INDEX idx_cleanup_invalid ON users(plan_id, transfer_enable, expired_at);
     */
    protected function countInvalidUsers(int $days): int
    {
        return $this->buildBaseQuery()
            ->leftJoin('v2_user as invited', 'invited.invite_user_id', 'v2_user.id')
            ->whereNull('invited.id')  // 从未邀请过他人
            ->whereNull('v2_user.plan_id')      // 未订阅
            ->where('v2_user.transfer_enable', 0)  // 无流量
            ->where(function ($query) {
                $query->where('v2_user.expired_at', 0)
                      ->orWhereNull('v2_user.expired_at');
            })   // 兼容旧版 expired_at=0 与新版 expired_at=NULL 的未过期无效用户
            ->where('v2_user.created_at', '<', time() - ($days * 86400))
            ->count();
    }

    /**
     * 统计过期用户数量
     *
     * 性能优化：利用 expired_at 和 created_at 索引进行范围查询
     * 所需索引：
     * - CREATE INDEX idx_cleanup_basic ON users(is_admin, balance, commission_balance, created_at);
     * - CREATE INDEX idx_cleanup_expired ON users(expired_at, created_at);
     */
    protected function countExpiredUsers(int $days, int $expiredDays): int
    {
        return $this->buildBaseQuery()
            ->where('v2_user.expired_at', '>', 0)
            ->where('v2_user.created_at', '<', time() - ($days * 86400))
            ->where('v2_user.expired_at', '<', time() - ($expiredDays * 86400))
            ->count();
    }

    protected function countOrphanTicketMessages(): int
    {
        return DB::table('v2_ticket_message')
            ->leftJoin('v2_ticket', 'v2_ticket.id', '=', 'v2_ticket_message.ticket_id')
            ->whereNull('v2_ticket.id')
            ->count();
    }

    protected function deleteOrphanTicketMessages(int $batchSize): int
    {
        $messageIds = DB::table('v2_ticket_message')
            ->leftJoin('v2_ticket', 'v2_ticket.id', '=', 'v2_ticket_message.ticket_id')
            ->whereNull('v2_ticket.id')
            ->orderBy('v2_ticket_message.id')
            ->limit($batchSize)
            ->pluck('v2_ticket_message.id');

        if ($messageIds->isEmpty()) {
            return 0;
        }

        $deleted = DB::table('v2_ticket_message')
            ->whereIn('id', $messageIds)
            ->delete();

        Log::warning('已清理孤儿工单消息', [
            'count' => $deleted,
            'first_id' => $messageIds->first(),
            'last_id' => $messageIds->last(),
        ]);

        return $deleted;
    }

    /**
     * 构建基础查询
     *
     * 性能优化：基础条件过滤，利用等值查询索引
     * 所需索引：CREATE INDEX idx_cleanup_basic ON users(is_admin, balance, commission_balance);
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function buildBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->where('v2_user.is_admin', false)
            ->where('v2_user.balance', 0)
            ->where('v2_user.commission_balance', 0);
    }

    private function getScanCursorKey(int $deleteExpiredAfterDays): string
    {
        return self::SCAN_CURSOR_KEY_PREFIX . ($deleteExpiredAfterDays > 0 ? 'expired' : 'invalid');
    }

    /**
     * @param Collection<int, User> $users
     */
    protected function deleteUsers(Collection $users): int
    {
        $userIds = $users->pluck('id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            Log::error('批量删除跳过：候选用户 ID 为空或无效');

            return 0;
        }

        try {
            $deleted = 0;

            DB::transaction(function () use ($userIds, &$deleted): void {
                $ticketIds = DB::table('v2_ticket')
                    ->whereIn('user_id', $userIds)
                    ->pluck('id');

                if ($ticketIds->isNotEmpty()) {
                    DB::table('v2_ticket_message')
                        ->whereIn('ticket_id', $ticketIds)
                        ->delete();
                }

                DB::table('v2_ticket_message')->whereIn('user_id', $userIds)->delete();
                DB::table('v2_order')->whereIn('user_id', $userIds)->delete();
                DB::table('v2_invite_code')->whereIn('user_id', $userIds)->delete();
                DB::table('v2_stat_user')->whereIn('user_id', $userIds)->delete();
                DB::table('v2_ticket')->whereIn('user_id', $userIds)->delete();

                $deleted = DB::table('v2_user')->whereIn('id', $userIds)->delete();
            }, 3);

            Log::warning('已批量删除用户', [
                'count' => $deleted,
                'candidate_count' => $userIds->count(),
                'first_id' => $userIds->first(),
                'last_id' => $userIds->last(),
            ]);

            return $deleted;
        } catch (\Throwable $exception) {
            Log::error('批量删除用户失败', [
                'candidate_count' => $userIds->count(),
                'first_id' => $userIds->first(),
                'last_id' => $userIds->last(),
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }
}
