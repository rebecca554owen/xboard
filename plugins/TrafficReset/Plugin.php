<?php

namespace Plugin\TrafficReset;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TrafficResetService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 该插件仅依赖定时任务，不注册运行时钩子
    }

    /**
     * 配置定时任务，驱动自动检测逻辑。
     */
    public function schedule(Schedule $schedule): void
    {
        $frequency = $this->getConfig('schedule_frequency', 'hourly');

        $task = $schedule->call(function () {
            if ($this->getConfig('enable_auto_reset', true)) {
                $this->checkAndResetTraffic();
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
                $task->hourly();
        }

        $task->name('traffic_reset_auto')->onOneServer();
    }

    /**
     * 批量扫描流量耗尽的用户，按需触发提前重置。
     */
    protected function checkAndResetTraffic(): void
    {
        $batchSize = $this->getConfig('batch_size', 100);

        try {
            $userIds = User::where('transfer_enable', '>', 0)
                ->where('banned', 0)
                ->whereRaw('(u + d) >= transfer_enable * 0.99')  // 使用99%阈值，避免刚好用完的情况
                ->whereNotNull('plan_id')
                ->whereHas('plan', function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('reset_traffic_method')
                            ->orWhere('reset_traffic_method', '!=', Plan::RESET_TRAFFIC_NEVER);
                    });
                })
                ->pluck('id');

            if ($userIds->isEmpty()) {
                return;
            }

            $successCount = 0;
            $userIds->chunk($batchSize)->each(function ($chunkedUserIds) use (&$successCount) {
                $users = User::with('plan')
                    ->whereIn('id', $chunkedUserIds)
                    ->get();

                foreach ($users as $user) {
                    $result = $this->resetUserTraffic($user);
                    if ($result) {
                        $successCount++;
                    }
                }
            });

            if ($successCount > 0) {
                Log::info("扫描到 " . $userIds->count() . " 个流量耗尽的用户，成功重置了 {$successCount} 个用户的流量");
            }
        } catch (\Exception $e) {
            Log::error('扫描执行失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 针对单个用户执行提前重置流程。
     */
    protected function resetUserTraffic(User $user): bool
    {
        try {
            // 检查是否支持自定义周期
            $isCustomPeriod = $this->isCustomPeriod($user->plan);

            $resetMethod = $user->plan->reset_traffic_method ?? admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);

            if ($isCustomPeriod) {
                $customEnabled = $this->getConfig('auto_reset_on_exceed_custom', true);
                if (!$customEnabled) {
                    return false;
                }

                // 检查自定义周期用户是否有足够的剩余时间（大于周期天数）
                $intervalDays = $this->getCustomIntervalDays($user->plan);
                $remainingDays = ($user->expired_at - time()) / 86400;
                if ($remainingDays <= $intervalDays) {
                    return false;
                }
            } else {
                // 原有逻辑：检查支持的策略
                if (!in_array($resetMethod, [Plan::RESET_TRAFFIC_MONTHLY, Plan::RESET_TRAFFIC_FIRST_DAY_MONTH], true)) {
                    return false;
                }

                $monthlyEnabled = $this->getConfig('auto_reset_on_exceed_monthly', true);
                $firstDayEnabled = $this->getConfig('auto_reset_on_exceed_first_day', true);

                if (
                    ($resetMethod === Plan::RESET_TRAFFIC_MONTHLY && !$monthlyEnabled) ||
                    ($resetMethod === Plan::RESET_TRAFFIC_FIRST_DAY_MONTH && !$firstDayEnabled)
                ) {
                    return false;
                }

                // 检查非自定义周期用户是否有足够的剩余时间（大于一个周期）
                $remainingDays = ($user->expired_at - time()) / 86400;
                $requiredDays = $resetMethod === Plan::RESET_TRAFFIC_MONTHLY ? 30 : 30; // 按月结日和每月一号都按30天计算
                if ($remainingDays <= $requiredDays) {
                    return false;
                }
            }

            $originalExpiredAt = $user->expired_at;
            $originalNextResetAt = $user->next_reset_at;

            DB::beginTransaction();

            $user->expired_at = $this->calculateNewExpiredAt($user->expired_at, $resetMethod, $user);

            $user->save();

            $trafficResetService = new TrafficResetService();
            $trafficResetService->performReset($user, TrafficResetLog::SOURCE_AUTO);

            // 对于自定义周期用户，需要手动更新 next_reset_at
            if ($isCustomPeriod) {
                $intervalDays = $this->getCustomIntervalDays($user->plan);
                if ($intervalDays > 0) {
                    // 下一个重置时间应该从当前时间开始计算一个周期后
                    $user->next_reset_at = strtotime("+{$intervalDays} days");
                    $user->save();
                }
            }

            DB::commit();

            $resetMethodText = $this->getResetMethodText($resetMethod, $isCustomPeriod);

            $logContext = [
                'user_id' => $user->id,
                'email' => $user->email,
                'strategy' => $resetMethodText,
                'expired_at_before' => $this->formatTimestamp($originalExpiredAt),
                'expired_at_after' => $this->formatTimestamp($user->expired_at),
            ];

            if ($user->next_reset_at !== $originalNextResetAt) {
                $logContext['next_reset_at_before'] = $this->formatTimestamp($originalNextResetAt);
                $logContext['next_reset_at_after'] = $this->formatTimestamp($user->next_reset_at);
            }

            Log::warning('提前重置用户流量', $logContext);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('重置用户流量失败', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 根据不同策略计算新的到期时间。
     */
    protected function calculateNewExpiredAt(int $currentExpiredAt, int $resetMethod, User $user): int
    {
        // 检查是否自定义周期
        if ($this->isCustomPeriod($user->plan)) {
            return $this->calculateCustomResetExpiredAt($user, $currentExpiredAt);
        }

        switch ($resetMethod) {
            case Plan::RESET_TRAFFIC_MONTHLY:
                return $this->calculateMonthlyResetExpiredAt($currentExpiredAt);

            case Plan::RESET_TRAFFIC_FIRST_DAY_MONTH:
                return $this->calculateFirstDayResetExpiredAt($currentExpiredAt);

            default:
                return $currentExpiredAt;
        }
    }

    /**
     * 对“按月结日”套餐，将到期日提前至今日对应的结日。
     */
    protected function calculateMonthlyResetExpiredAt(int $currentExpiredAt): int
    {
        $currentDay = (int) date('d');
        $currentHour = (int) date('H');
        $currentMinute = (int) date('i');
        $currentSecond = (int) date('s');

        $expireDay = (int) date('d', $currentExpiredAt);
        $daysDiff = $expireDay - $currentDay;

        $newExpiredAt = $daysDiff > 0
            ? strtotime("-$daysDiff days", $currentExpiredAt)
            : strtotime('-1 month', $currentExpiredAt);

        return mktime(
            $currentHour,
            $currentMinute,
            $currentSecond,
            (int) date('m', $newExpiredAt),
            (int) date('d', $newExpiredAt),
            (int) date('Y', $newExpiredAt)
        );
    }

    /**
     * 对"每月一号"套餐，直接回拨一个月保持时分秒。
     */
    protected function calculateFirstDayResetExpiredAt(int $currentExpiredAt): int
    {
        return strtotime('-1 month', $currentExpiredAt);
    }

    /**
     * 检查套餐是否使用自定义周期
     */
    protected function isCustomPeriod(?Plan $plan): bool
    {
        if (!$plan || !$plan->tags) {
            return false;
        }

        $tags = is_array($plan->tags) ? implode(',', $plan->tags) : (string)$plan->tags;
        return strpos($tags, 'interval_days:') !== false;
    }

    /**
     * 计算自定义周期的新到期时间
     * 提前重置应该扣除一个周期的天数作为代价
     * 从原到期时间中扣除一个周期
     */
    protected function calculateCustomResetExpiredAt(User $user, int $currentExpiredAt): int
    {
        $intervalDays = $this->getCustomIntervalDays($user->plan);

        if ($intervalDays <= 0) {
            return $currentExpiredAt;
        }

        // 获取当前时分秒（与按月结日逻辑保持一致）
        $currentHour = (int) date('H');
        $currentMinute = (int) date('i');
        $currentSecond = (int) date('s');

        // 计算新的到期日期：从原来的到期时间中扣除一个周期
        $newExpiredDate = strtotime("-{$intervalDays} days", $currentExpiredAt);

        // 使用 mktime 设置正确的时分秒
        return mktime(
            $currentHour,
            $currentMinute,
            $currentSecond,
            (int) date('m', $newExpiredDate),
            (int) date('d', $newExpiredDate),
            (int) date('Y', $newExpiredDate)
        );
    }

    /**
     * 从套餐标签获取自定义周期天数
     */
    protected function getCustomIntervalDays(?Plan $plan): int
    {
        if (!$plan || !$plan->tags) {
            return 0;
        }

        $tags = is_array($plan->tags) ? implode(',', $plan->tags) : (string)$plan->tags;
        $tagArray = explode(',', $tags);

        foreach ($tagArray as $tag) {
            $tag = trim($tag);
            if (strpos($tag, 'interval_days:') === 0) {
                $value = substr($tag, strlen('interval_days:'));
                $value = trim($value);
                if (is_numeric($value) && $value > 0) {
                    return (int)$value;
                }
            }
        }

        return 0;
    }


    /**
     * 输出中文策略名称，便于日志查看。
     */
    protected function getResetMethodText($resetMethod, bool $isCustomPeriod = false): string
    {
        if ($isCustomPeriod) {
            return '自定义周期';
        }

        $mapping = [
            null => '跟随系统设置',
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => '每月 1 号',
            Plan::RESET_TRAFFIC_MONTHLY => '按月结日',
            Plan::RESET_TRAFFIC_NEVER => '不重置',
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => '每年 1 月 1 日',
            Plan::RESET_TRAFFIC_YEARLY => '按年结日',
        ];

        return $mapping[$resetMethod] ?? '未知方式';
    }

  
    /**
     * 将时间戳格式化为可读字符串。
     */
    protected function formatTimestamp(?int $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
