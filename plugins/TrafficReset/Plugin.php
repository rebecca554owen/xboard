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
     *
     * 性能优化说明：
     * 1. 使用 chunkById() 游标处理，避免一次性加载所有用户到内存
     * 2. 添加 orderBy('id') 确保使用主键索引，提高查询效率
     * 3. 分批处理默认 100 条，可配置平衡内存和性能
     * 4. 在查询中直接预加载 plan 关系，避免 N+1 查询问题
     */
    protected function checkAndResetTraffic(): void
    {
        // 性能优化：使用 chunkById 游标处理，避免一次性加载大量数据到内存
        $chunkSize = $this->getConfig('batch_size', 100);

        try {
            $successCount = 0;
            $totalProcessed = 0;

            // 性能优化要点：
            // 1. chunkById 使用主键游标，避免内存溢出
            // 2. orderBy('id') 确保使用主键索引，提高查询性能
            // 3. with('plan') 预加载套餐信息，避免 N+1 查询
            User::where('transfer_enable', '>', 0)
                ->where('banned', 0)
                ->whereRaw('(u + d) >= transfer_enable * 0.99')  // 使用99%阈值，避免刚好用完的情况
                ->whereNotNull('plan_id')
                ->whereHas('plan', function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('reset_traffic_method')
                            ->orWhere('reset_traffic_method', '!=', Plan::RESET_TRAFFIC_NEVER);
                    });
                })
                ->with('plan')  // 预加载套餐关系，避免 N+1 查询
                ->orderBy('id')  // 确保使用主键索引
                ->chunkById($chunkSize, function ($users) use (&$successCount, &$totalProcessed) {
                    foreach ($users as $user) {
                        $totalProcessed++;
                        $result = $this->resetUserTraffic($user);
                        if ($result) {
                            $successCount++;
                        }
                    }

                    // 性能优化：每处理完一批，释放内存
                    // GC 由 Laravel 自动处理，但显式unset有助于及时释放
                    unset($users);
                });

            if ($successCount > 0) {
                Log::info("扫描处理了 {$totalProcessed} 个流量耗尽的用户，成功重置了 {$successCount} 个用户的流量");
            }
        } catch (\Exception $e) {
            Log::error('流量扫描执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 针对单个用户执行提前重置流程。
     *
     * 该方法已被拆分为多个子方法以提高可读性和可维护性：
     * - shouldResetUserTraffic(): 判断是否应该重置
     * - applyTrafficReset(): 执行重置操作
     * - logTrafficReset(): 记录日志
     */
    protected function resetUserTraffic(User $user): bool
    {
        try {
            if (!$this->shouldResetUserTraffic($user)) {
                return false;
            }

            return $this->applyTrafficReset($user);
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
     * 判断用户是否应该执行流量重置
     *
     * 检查条件：
     * 1. 套餐是否支持重置策略
     * 2. 插件配置是否允许该类型的重置
     * 3. 用户是否有足够的剩余时间
     */
    protected function shouldResetUserTraffic(User $user): bool
    {
        $isCustomPeriod = $this->isCustomPeriod($user->plan);
        $resetMethod = $user->plan->reset_traffic_method ?? admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);

        if ($isCustomPeriod) {
            return $this->shouldResetCustomPeriodUser($user);
        }

        return $this->shouldResetMonthlyUser($user, $resetMethod);
    }

    /**
     * 判断自定义周期用户是否应该重置
     */
    protected function shouldResetCustomPeriodUser(User $user): bool
    {
        $customEnabled = $this->getConfig('auto_reset_on_exceed_custom', true);
        if (!$customEnabled) {
            return false;
        }

        $intervalDays = $this->getCustomIntervalDays($user->plan);
        $remainingDays = ($user->expired_at - time()) / 86400;

        return $remainingDays > $intervalDays;
    }

    /**
     * 判断按月重置用户是否应该重置
     */
    protected function shouldResetMonthlyUser(User $user, int $resetMethod): bool
    {
        $monthlyEnabled = $this->getConfig('auto_reset_on_exceed_monthly', true);
        $firstDayEnabled = $this->getConfig('auto_reset_on_exceed_first_day', true);

        if ($resetMethod === Plan::RESET_TRAFFIC_MONTHLY && !$monthlyEnabled) {
            return false;
        }

        if ($resetMethod === Plan::RESET_TRAFFIC_FIRST_DAY_MONTH && !$firstDayEnabled) {
            return false;
        }

        return in_array($resetMethod, [Plan::RESET_TRAFFIC_MONTHLY, Plan::RESET_TRAFFIC_FIRST_DAY_MONTH], true)
            && ($user->expired_at - time()) / 86400 > 30;
    }

    /**
     * 执行流量重置操作
     *
     * 包括：
     * 1. 计算新的到期时间
     * 2. 执行数据库事务
     * 3. 调用流量重置服务
     * 4. 记录日志
     */
    protected function applyTrafficReset(User $user): bool
    {
        $isCustomPeriod = $this->isCustomPeriod($user->plan);
        $resetMethod = $user->plan->reset_traffic_method ?? admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);

        $originalExpiredAt = $user->expired_at;
        $originalNextResetAt = $user->next_reset_at;

        DB::beginTransaction();

        $user->expired_at = $this->calculateNewExpiredAt($user->expired_at, $resetMethod, $user);
        $user->save();

        $trafficResetService = new TrafficResetService();
        $trafficResetService->performReset($user, TrafficResetLog::SOURCE_AUTO);

        if ($isCustomPeriod) {
            $intervalDays = $this->getCustomIntervalDays($user->plan);
            if ($intervalDays > 0) {
                $user->next_reset_at = strtotime("+{$intervalDays} days");
                $user->save();
            }
        }

        DB::commit();

        $this->logTrafficReset($user, $resetMethod, $isCustomPeriod, $originalExpiredAt, $originalNextResetAt);

        return true;
    }

    /**
     * 记录流量重置日志
     */
    protected function logTrafficReset(User $user, int $resetMethod, bool $isCustomPeriod, int $originalExpiredAt, ?int $originalNextResetAt): void
    {
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
     * 对”按月结日”套餐，将到期日提前至今日对应的结日。
     */
    protected function calculateMonthlyResetExpiredAt(int $currentExpiredAt): int
    {
        $currentDay = (int) date('d');
        $expireDay = (int) date('d', $currentExpiredAt);
        $daysDiff = $expireDay - $currentDay;

        $newExpiredAt = $daysDiff > 0
            ? strtotime("-$daysDiff days", $currentExpiredAt)
            : strtotime('-1 month', $currentExpiredAt);

        return $this->applyCurrentTimeOfDay($newExpiredAt);
    }

    /**
     * 对"每月一号"套餐，直接回拨一个月保持时分秒。
     */
    protected function calculateFirstDayResetExpiredAt(int $currentExpiredAt): int
    {
        return strtotime('-1 month', $currentExpiredAt);
    }

    /**
     * 检查套餐是否使用自定义周期或提取周期天数
     */
    protected function getPlanTags(?Plan $plan): string
    {
        if (!$plan || !$plan->tags) {
            return '';
        }

        $tags = is_array($plan->tags) ? implode(',', $plan->tags) : (string)$plan->tags;
        return $tags;
    }

    /**
     * 检查套餐是否使用自定义周期
     */
    protected function isCustomPeriod(?Plan $plan): bool
    {
        return strpos($this->getPlanTags($plan), 'interval_days:') !== false;
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

        $newExpiredDate = strtotime("-{$intervalDays} days", $currentExpiredAt);

        return $this->applyCurrentTimeOfDay($newExpiredDate);
    }

    /**
     * 将当前时分秒应用到指定时间戳上
     */
    private function applyCurrentTimeOfDay(int $timestamp): int
    {
        [$h, $i, $s] = explode(':', date('H:i:s'));
        return mktime((int)$h, (int)$i, (int)$s, (int)date('m', $timestamp), (int)date('d', $timestamp), (int)date('Y', $timestamp));
    }

    /**
     * 从套餐标签获取自定义周期天数
     */
    protected function getCustomIntervalDays(?Plan $plan): int
    {
        $tags = $this->getPlanTags($plan);
        if (!$tags) {
            return 0;
        }

        foreach (explode(',', $tags) as $tag) {
            $tag = trim($tag);
            if (strpos($tag, 'interval_days:') === 0) {
                $value = trim(substr($tag, strlen('interval_days:')));
                if (is_numeric($value) && $value > 0) {
                    return (int)$value;
                }
            }
        }

        return 0;
    }

    /**
     * 输出中文策略名称
     */
    protected function getResetMethodText($resetMethod, bool $isCustomPeriod = false): string
    {
        if ($isCustomPeriod) {
            return '自定义周期';
        }

        return [
            null => '跟随系统设置',
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => '每月 1 号',
            Plan::RESET_TRAFFIC_MONTHLY => '按月结日',
            Plan::RESET_TRAFFIC_NEVER => '不重置',
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => '每年 1 月 1 日',
            Plan::RESET_TRAFFIC_YEARLY => '按年结日',
        ][$resetMethod] ?? '未知方式';
    }

    /**
     * 将时间戳格式化为可读字符串
     */
    protected function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
