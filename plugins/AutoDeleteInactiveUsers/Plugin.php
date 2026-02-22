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
        $enableAutoDelete = (bool) $this->getConfig('enable_auto_delete', false);
        $deleteExpiredAfterDays = max(0, (int) $this->getConfig('delete_expired_users_after_days', 0));

        try {
            $invalidUserCount = $this->countInvalidUsers($days);
            $expiredUserCount = $this->countExpiredUsers($days, $deleteExpiredAfterDays);

            if ($enableAutoDelete) {
                $totalDeleted = 0;
                $retryCount = 0;
                $maxRetries = 3;

                while (true) {
                    $candidates = $this->queryCandidateUsers($days, $batchSize, $deleteExpiredAfterDays);
                    if ($candidates->isEmpty()) {
                        break;
                    }

                    $batchDeleted = 0;

                    foreach ($candidates as $user) {
                        if ($this->deleteUser($user)) {
                            ++$batchDeleted;
                            ++$totalDeleted;
                        }
                    }

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
                    }
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
    protected function queryCandidateUsers(int $days, int $batchSize, int $deleteExpiredAfterDays = 0): Collection
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
                  ->where('v2_user.expired_at', 0);   // 未过期
        }

        // 性能优化：添加时间过滤，利用 created_at 索引
        // 所需索引：CREATE INDEX idx_cleanup_basic ON users(is_admin, balance, commission_balance, created_at);
        // 重要：使用 select 确保只返回主表字段，避免 LEFT JOIN 导致的 NULL 值污染
        return $query->where('v2_user.created_at', '<', time() - ($days * 86400))
                     ->select('v2_user.*')  // 只选择主表字段，避免 JOIN 污染
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
            ->where('v2_user.expired_at', 0)   // 未过期
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

    protected function deleteUser(User $user): bool
    {
        try {
            // 验证用户 ID 有效性，防止删除无效数据
            if (!$user->id || $user->id <= 0) {
                Log::error('尝试删除无效用户', [
                    'user_id' => $user->id,
                    'email' => $user->email ?? 'N/A',
                ]);
                return false;
            }

            DB::beginTransaction();

            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();

            DB::commit();

            Log::warning(sprintf(
                '已删除用户 ID=%d, Email=%s, 注册时间=%s',
                $user->id,
                $user->email,
                date('Y-m-d H:i:s', $user->created_at)
            ));

            return true;
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('删除用户失败', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}

