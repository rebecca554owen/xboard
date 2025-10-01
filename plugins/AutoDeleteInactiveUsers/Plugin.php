<?php

namespace Plugin\AutoDeleteInactiveUsers;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
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
            if ($this->getConfig('enable_auto_delete', false)) {
                $this->deleteInactiveUsers();
            }
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
    }

    /**
     * 删除长期未激活且无邀请关系的用户，并按需执行试运行。
     */
    protected function deleteInactiveUsers(): void
    {
        if (!Cache::add(self::LOCK_KEY, 1, self::LOCK_TTL)) {
            Log::info('检测到任务已在运行，跳过本轮执行');

            return;
        }

        $days = max(1, (int) $this->getConfig('delete_days', 7));
        $batchSize = max(1, (int) $this->getConfig('batch_size', 100));
        $dryRun = (bool) $this->getConfig('dry_run', true);
        $deletedCount = 0;

        try {
            while (true) {
                $candidates = $this->queryCandidateUsers($days, $batchSize);
                if ($candidates->isEmpty()) {
                    break;
                }

                $batchDeleted = 0;

                foreach ($candidates as $user) {
                    if ($dryRun) {
                        ++$batchDeleted;
                        ++$deletedCount;
                        continue;
                    }

                    if ($this->deleteUser($user)) {
                        ++$batchDeleted;
                        ++$deletedCount;
                    }
                }

                if ($batchDeleted === 0) {
                    continue;
                }

                if ($dryRun) {
                    Log::warning(sprintf(
                        '[试运行]本轮匹配 %d 个符合条件用户，未执行真实删除',
                        $batchDeleted
                    ));
                } else {
                    Log::info(sprintf(
                        '本轮删除 %d 个用户，累计删除 %d 个',
                        $batchDeleted,
                        $deletedCount
                    ));
                }
            }

            if ($dryRun) {
                Log::warning(sprintf(
                    '[试运行]完成：共计匹配 %d 个注册超过 %d 天且未激活的用户',
                    $deletedCount,
                    $days
                ));
            } elseif ($deletedCount > 0) {
                Log::info(sprintf(
                    '完成：共删除 %d 个注册超过 %d 天且未激活的用户',
                    $deletedCount,
                    $days
                ));
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
    protected function queryCandidateUsers(int $days, int $batchSize): Collection
    {
        return User::query()
            ->whereNull('plan_id')
            ->where('transfer_enable', 0)
            ->where('expired_at', 0)
            ->whereNull('last_login_at')
            ->where('is_admin', false)
            ->whereNotIn('id', User::query()
                ->whereNotNull('invite_user_id')
                ->select('invite_user_id')
            )
            ->where('created_at', '<', time() - ($days * 86400))
            ->limit($batchSize)
            ->get();
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

