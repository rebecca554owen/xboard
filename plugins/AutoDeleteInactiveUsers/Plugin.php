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
    private const LOCK_TTL = 300; // 缓存锁有效期 5 分钟，避免并发任务重复执行

    public function boot(): void
    {
        // 插件启动逻辑（当前无特殊配置）
    }

    /**
     * 注册定时任务，根据配置频率执行清理逻辑。
     */
    public function schedule(Schedule $schedule): void
    {
        $frequency = $this->getConfig('schedule_frequency', 'daily');

        $task = $schedule->call(function (): void {
            // 定时任务始终执行，根据 enable_auto_delete 决定是否真实删除
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
                $task->dailyAt('00:00');
                break;
            default:
                $task->dailyAt('00:00');
                break;
        }

        $task->name('auto_delete_inactive_users')->onOneServer();
    }

    /**
     * 清理无效用户和过期n天未续费用户，支持试运行模式。
     */
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
        $invalidUserCount = 0;
        $expiredUserCount = 0;

        try {
            // 试运行模式：同时统计两种用户
            $invalidUserCount = $this->countInvalidUsers($days);
            $expiredUserCount = $this->countExpiredUsers($days, $deleteExpiredAfterDays);

            if ($enableAutoDelete) {
                // 真实删除模式
                $totalDeleted = 0;
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
                        continue;
                    }
                }

                // 更新统计变量用于最终日志
                if ($deleteExpiredAfterDays > 0) {
                    $expiredUserCount = $totalDeleted;
                } else {
                    $invalidUserCount = $totalDeleted;
                }
            }

            if (!$enableAutoDelete) {
                // 试运行模式：显示两种用户的统计结果
                if ($deleteExpiredAfterDays > 0) {
                    Log::warning(sprintf(
                        '[试运行]注册%d天以上无效用户%d个，过期时间大于%d天用户%d个',
                        $days,
                        $invalidUserCount,
                        $deleteExpiredAfterDays,
                        $expiredUserCount
                    ));
                } else {
                    Log::warning(sprintf(
                        '[试运行]注册%d天以上无效用户%d个，所有过期用户%d个',
                        $days,
                        $invalidUserCount,
                        $expiredUserCount
                    ));
                }
            } else {
                // 真实删除模式：显示删除结果
                $totalDeleted = $invalidUserCount + $expiredUserCount;
                if ($totalDeleted > 0) {
                    Log::info(sprintf(
                        '完成：共删除 %d 个用户',
                        $totalDeleted
                    ));
                }
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
     * 查询符合清理条件的用户列表。
     *
     * @return Collection<int, User>
     */
    protected function queryCandidateUsers(int $days, int $batchSize, int $deleteExpiredAfterDays = 0): Collection
    {
        $query = User::query()
            ->where('is_admin', false); // 非管理员

        if ($deleteExpiredAfterDays > 0) {
            // 过期用户清理模式：包含过期超过指定天数的用户
            $query->where('expired_at', '>', 0) // 已设置过期时间
                  ->where('expired_at', '<', time() - ($deleteExpiredAfterDays * 86400)) // 过期超过指定天数
                  ->where('balance', 0) // 余额为0
                  ->where('commission_balance', 0); // 佣金余额为0
        } else {
            // 基础清理模式：只删除无套餐、无流量、未设置到期时间的用户
            $query->whereNull('plan_id') // 无套餐
                  ->where('transfer_enable', 0) // 无流量
                  ->where('expired_at', 0) // 未设置到期时间
                  ->where('balance', 0) // 余额为0
                  ->where('commission_balance', 0) // 佣金余额为0
                  ->whereNotIn('id', User::query()
                      ->whereNotNull('invite_user_id')
                      ->select('invite_user_id')
                  ); // 无邀请关系
        }

        return $query->where('created_at', '<', time() - ($days * 86400)) // 注册超过指定天数
                     ->limit($batchSize) // 限制批次大小
                     ->get();
    }

    /**
     * 统计无效用户数量（基础清理模式）
     */
    protected function countInvalidUsers(int $days): int
    {
        return User::query()
            ->where('is_admin', false) // 非管理员
            ->whereNull('plan_id') // 无套餐
            ->where('transfer_enable', 0) // 无流量
            ->where('expired_at', 0) // 未设置到期时间
            ->where('balance', 0) // 余额为0
            ->where('commission_balance', 0) // 佣金余额为0
            ->whereNotIn('id', User::query()
                ->whereNotNull('invite_user_id')
                ->select('invite_user_id')
            ) // 无邀请关系
            ->where('created_at', '<', time() - ($days * 86400)) // 注册超过指定天数
            ->count();
    }

    /**
     * 统计过期用户数量（过期用户清理模式）
     */
    protected function countExpiredUsers(int $days, int $expiredDays): int
    {
        $query = User::query()
            ->where('is_admin', false) // 非管理员
            ->where('expired_at', '>', 0) // 已设置过期时间
            ->where('balance', 0) // 余额为0
            ->where('commission_balance', 0) // 佣金余额为0
            ->where('created_at', '<', time() - ($days * 86400)); // 注册超过指定天数

        if ($expiredDays > 0) {
            // 统计过期超过指定天数的用户
            $query->where('expired_at', '<', time() - ($expiredDays * 86400)); // 过期超过指定天数
        } else {
            // 统计所有过期用户
            $query->where('expired_at', '<', time()); // 已过期
        }

        return $query->count();
    }

    /**
     * 删除指定用户及关联业务数据。
     */
    protected function deleteUser(User $user): bool
    {
        try {
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

