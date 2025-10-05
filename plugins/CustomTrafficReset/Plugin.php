<?php

namespace Plugin\CustomTrafficReset;

use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Scheduling\Schedule;

/**
 * 自定义流量重置时间插件
 * 通过套餐标签配置重置周期，支持异步于系统默认的重置逻辑
 */
class Plugin extends AbstractPlugin
{
    /**
     * 插件启动方法
     */
    public function boot(): void
    {
        $this->registerHooks();
    }

    /**
     * 配置定时任务
     */
    public function schedule(Schedule $schedule): void
    {
        // 合并检查：套餐标签变更和重置时间错误
        $checkInterval = $this->getConfig('check_interval_minutes', 5);

        $schedule->call(function () {
            if ($this->shouldProcess()) {
                $this->checkPlanTagsAndRecalculate();
                $this->checkAndFixIncorrectResetTimes();
            }
        })->cron("*/{$checkInterval} * * * *")->name('custom_traffic_reset_combined_check')->onOneServer();
    }

    /**
     * 注册事件钩子
     */
    protected function registerHooks(): void
    {
        // 订单开通后计算重置时间和到期时间
        $this->listen('order.open.after', function ($order) {
            if ($this->shouldProcess() && $order->user) {
                Log::info("订单开通触发，订单ID {$order->id}，用户 {$order->user->email}，到期时间 {$order->expired_at}");
                $this->calculateNextResetAt($order->user, 'order');
                $this->calculateExpiredAt($order->user, $order->period, 'order');
            }
        });

        // 流量重置后重新计算
        $this->listen('traffic.reset.after', function (User $user) {
            if ($this->shouldProcess()) {
                Log::info("流量重置触发，用户 {$user->email}，到期时间 {$user->expired_at}");  
                $this->calculateNextResetAt($user, 'traffic_reset');
            }
        });

    }

    /**
     * 检查插件是否启用
     */
    protected function shouldProcess(): bool
    {
        return $this->getConfig('enabled', true);
    }

    /**
     * 计算用户下次重置时间
     */
    protected function calculateNextResetAt(User $user, string $trigger = 'unknown'): void
    {
        try {
            if (!$user->plan_id) {
                return;
            }

            $intervalDays = $this->getResetIntervalDays($user->plan);

            if ($intervalDays <= 0) {
                // 与系统默认逻辑保持一致，返回 null 表示不需要重置
                $this->updateResetTime($user, 0, $trigger);
                return;
            }

            $this->updateResetTime($user, $intervalDays, $trigger);

        } catch (\Exception $e) {
            Log::error('计算重置时间失败', [
                'user_id' => $user->id,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取重置间隔天数
     * 优先从套餐标签中获取，否则使用默认配置
     */
    protected function getResetIntervalDays(Plan $plan): int
    {
        $intervalDays = $this->parseIntervalFromTags($plan->tags);

        if ($intervalDays !== null) {
            return $intervalDays;
        }

        return $this->getConfig('default_interval_days', 30);
    }

    /**
     * 标准化标签字符串
     * 将数组或字符串统一转换为逗号分隔的字符串
     */
    protected function normalizeTags($tags): string
    {
        if (!$tags) {
            return '';
        }

        if (is_array($tags)) {
            return implode(',', $tags);
        }

        return (string)$tags;
    }

    /**
     * 从标签中解析重置间隔
     * 支持格式：interval_days:7
     */
    protected function parseIntervalFromTags($tags): ?int
    {
        $normalizedTags = $this->normalizeTags($tags);

        if (empty($normalizedTags)) {
            return null;
        }

        $tagArray = explode(',', $normalizedTags);

        foreach ($tagArray as $tag) {
            $tag = trim($tag);

            if (strpos($tag, 'interval_days:') === 0) {
                $value = substr($tag, strlen('interval_days:'));
                $value = trim($value);

                if (is_numeric($value) && $value >= 0) {
                    return (int) $value;
                }
            }
        }

        return null;
    }


    /**
     * 更新用户重置时间
     * 仅在时间发生变化时更新数据库
     */
    protected function updateResetTime(User $user, int $intervalDays, string $trigger = 'unknown'): void
    {
        // 当 intervalDays <= 0 时，设置 next_reset_at 为 null，与系统默认逻辑保持一致
        $nextResetAt = $intervalDays <= 0 ? null : $this->calculateNextResetTime($intervalDays, $user);

        if ($user->next_reset_at != $nextResetAt) {
            DB::beginTransaction();
            try {
                User::where('id', $user->id)->update(['next_reset_at' => $nextResetAt]);
                DB::commit();

                if ($nextResetAt === null) {
                    Log::info("为用户 {$user->email} 清除重置时间（不需要自动重置），触发来源：{$trigger}");
                } else {
                    Log::warning("为用户 {$user->email} 设置下次重置时间为：" . date('Y-m-d H:i:s', $nextResetAt) . "，触发来源：{$trigger}");
                }
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }

    /**
     * 计算下次重置时间
     * 自定义周期从当前时间开始计算，固定周期基于上次重置时间
     */
    protected function calculateNextResetTime(int $intervalDays, User $user): int
    {
        $currentTime = time();

        // 判断是否为自定义周期（通过套餐标签配置）
        $isCustomPeriod = $this->parseIntervalFromTags($user->plan->tags) !== null;

        if ($isCustomPeriod) {
            // 自定义周期：新周期从当前时间开始计算
            $baseTime = $currentTime;
        } else {
            // 固定周期（月结日等）：优先使用上次重置时间作为基准
            if ($user->last_reset_at && $user->last_reset_at > 0) {
                $baseTime = $user->last_reset_at;
            } else {
                $baseTime = $currentTime;
            }
        }

        // 计算下次重置时间
        $nextResetAt = strtotime("+{$intervalDays} days", $baseTime);

        // 如果计算出的重置时间在过去，调整到未来
        if ($nextResetAt < $currentTime) {
            while ($nextResetAt < $currentTime) {
                $nextResetAt = strtotime("+{$intervalDays} days", $nextResetAt);
            }
        }

        // 确保重置时间不超过用户到期时间
        if ($user->expired_at && $user->expired_at > $currentTime) {
            if ($nextResetAt > $user->expired_at) {
                $nextResetAt = $user->expired_at;
            }
        }

        return $nextResetAt;
    }

    /**
     * 检查套餐标签变更并重新计算
     */
    protected function checkPlanTagsAndRecalculate(): void
    {
        try {
            $lastCheckTime = $this->getLastCheckTime();
            $currentTime = time();

            $recentlyUpdatedPlans = Plan::where('updated_at', '>', $lastCheckTime)
                ->orderBy('updated_at', 'desc')
                ->get();

            if ($recentlyUpdatedPlans->isEmpty()) {
                return;
            }

            $processedCount = 0;
            foreach ($recentlyUpdatedPlans as $plan) {
                if ($this->hasIntervalDaysTags($plan->tags) || $this->hasExpiredDaysTags($plan->tags)) {
                    $this->recalculateAllUsersForPlan($plan);
                    $processedCount++;
                }
            }

            if ($recentlyUpdatedPlans->count() > 0 && $processedCount > 0) {
                Log::info("检查到 {$recentlyUpdatedPlans->count()} 个更新的套餐，其中 {$processedCount} 个套餐需要重新计算重置时间");
            }

            $this->updateLastCheckTime($currentTime);

        } catch (\Exception $e) {
            Log::error('检查套餐标签变更失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 检查标签是否包含 interval_days
     */
    protected function hasIntervalDaysTags($tags): bool
    {
        $normalizedTags = $this->normalizeTags($tags);
        return strpos($normalizedTags, 'interval_days:') !== false;
    }

    /**
     * 检查标签是否包含 expired_days
     */
    protected function hasExpiredDaysTags($tags): bool
    {
        $normalizedTags = $this->normalizeTags($tags);
        return strpos($normalizedTags, 'expired_days:') !== false;
    }

    /**
     * 批量重新计算套餐用户重置时间
     */
    protected function recalculateAllUsersForPlan(Plan $plan): void
    {
        $batchSize = $this->getConfig('batch_size', 100);
        $processedCount = 0;

        try {
            User::where('plan_id', $plan->id)
                ->orderBy('id')
                ->chunk($batchSize, function ($users) use (&$processedCount) {
                    foreach ($users as $user) {
                        $this->calculateNextResetAt($user);
                        // 批量处理时无法获取订单周期，使用默认计算逻辑
                        $this->calculateExpiredAt($user, null, 'scheduled_task');
                        $processedCount++;
                    }
                });

            Log::info("完成重新计算套餐 {$plan->name} 的用户重置时间，共处理 {$processedCount} 个用户");

        } catch (\Exception $e) {
            Log::error('批量重新计算用户重置时间失败', [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'processed_count' => $processedCount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取最后检查时间
     */
    protected function getLastCheckTime(): int
    {
        return cache()->get('custom_traffic_reset_last_check_time', 0);
    }

    /**
     * 更新最后检查时间
     */
    protected function updateLastCheckTime(int $time): void
    {
        cache()->put('custom_traffic_reset_last_check_time', $time, 60 * 60 * 24);
    }

    /**
     * 计算用户到期时间
     * 根据套餐标签中的周期天数和订单周期计算，支持季付/半年付等场景
     */
    protected function calculateExpiredAt(User $user, ?string $period = null, string $trigger = 'unknown'): void
    {
        try {
            if (!$this->getConfig('enable_expired_at_calculation', true)) {
                return;
            }

            if (!$user->plan_id) {
                return;
            }

            $expiredDays = $this->getExpiredDays($user->plan, $period);

            if ($expiredDays <= 0) {
                return;
            }

            $this->updateExpiredAt($user, $expiredDays, $trigger);

        } catch (\Exception $e) {
            Log::error('计算到期时间失败', [
                'user_id' => $user->id,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取到期天数
     * 根据套餐标签中的基础周期和订单周期计算实际到期天数
     */
    protected function getExpiredDays(Plan $plan, ?string $period = null): int
    {
        // 获取基础周期天数（相当于月付的基础天数）
        $baseDays = $this->getResetIntervalDays($plan);

        // 如果没有订单周期信息，返回基础周期
        if (!$period) {
            return $baseDays;
        }

        // 根据订单周期计算乘数
        $multiplier = $this->getPeriodMultiplier($period);

        // 计算实际到期天数
        return $baseDays * $multiplier;
    }

    /**
     * 根据订单周期获取乘数
     * 与 OrderService 中的 STR_TO_TIME 映射保持一致
     */
    protected function getPeriodMultiplier(string $period): int
    {
        $period = strtolower($period);

        $multipliers = [
            'monthly' => 1,      // 月付
            'quarterly' => 3,    // 季付
            'half_yearly' => 6,  // 半年付
            'yearly' => 12,      // 年付
            'two_yearly' => 24,  // 两年付
            'three_yearly' => 36 // 三年付
        ];

        return $multipliers[$period] ?? 1;
    }


    /**
     * 更新用户到期时间
     * 仅在时间发生变化时更新数据库
     */
    protected function updateExpiredAt(User $user, int $expiredDays, string $trigger = 'unknown'): void
    {
        $expiredAt = $this->calculateExpiredTime($expiredDays);

        if ($user->expired_at != $expiredAt) {
            DB::beginTransaction();
            try {
                User::where('id', $user->id)->update(['expired_at' => $expiredAt]);
                DB::commit();

                Log::warning("为用户 {$user->email} 设置到期时间为：" . date('Y-m-d H:i:s', $expiredAt) . "，触发来源：{$trigger}");
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }

    /**
     * 计算到期时间
     */
    protected function calculateExpiredTime(int $expiredDays): int
    {
        return strtotime("+{$expiredDays} days");
    }

    /**
     * 检查和修复计算错误的 next_reset_at 时间
     * 主要修复：
     * 1. 自定义周期用户被 TrafficReset 插件重置后，next_reset_at 没有被正确更新
     * 2. 下次重置时间与预期周期不符
     */
    protected function checkAndFixIncorrectResetTimes(): void
    {
        try {
            $batchSize = $this->getConfig('batch_size', 100);
            $fixedCount = 0;

            // 查找所有有自定义周期套餐的用户
            User::whereNotNull('plan_id')
                ->where('banned', 0)
                ->where('expired_at', '>', time())
                ->with('plan')
                ->chunk($batchSize, function ($users) use (&$fixedCount) {
                    foreach ($users as $user) {
                        if ($this->shouldRecalculateResetTime($user)) {
                            $this->calculateNextResetAt($user, 'scheduled_fix');
                            $fixedCount++;
                        }
                    }
                });

            if ($fixedCount > 0) {
                Log::info("修复了 {$fixedCount} 个用户的 next_reset_at 时间");
            }

        } catch (\Exception $e) {
            Log::error('检查和修复重置时间失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 判断是否需要重新计算重置时间
     * 条件：
     * 1. 用户有套餐
     * 2. 套餐有自定义周期标签
     * 3. next_reset_at 为空或与预期周期不符
     */
    protected function shouldRecalculateResetTime(User $user): bool
    {
        if (!$user->plan) {
            return false;
        }

        $intervalDays = $this->getResetIntervalDays($user->plan);
        if ($intervalDays <= 0) {
            return false;
        }

        // 如果 next_reset_at 为空，需要重新计算
        if (!$user->next_reset_at) {
            return true;
        }

        // 检查重置时间是否与预期周期相符
        $expectedResetAt = $this->calculateExpectedResetTime($user, $intervalDays);
        $timeDiff = abs($user->next_reset_at - $expectedResetAt);

        // 如果时间差超过1天，认为需要修复
        return $timeDiff > 86400;
    }

    /**
     * 计算预期的重置时间
     */
    protected function calculateExpectedResetTime(User $user, int $intervalDays): int
    {
        $baseTime = $user->last_reset_at ?: time();
        $expectedResetAt = strtotime("+{$intervalDays} days", $baseTime);

        // 确保重置时间不超过到期时间
        if ($user->expired_at && $expectedResetAt > $user->expired_at) {
            $expectedResetAt = $user->expired_at;
        }

        return $expectedResetAt;
    }

    /**
     * 插件卸载时清理
     */
    public function cleanup(): void
    {
        Log::info('CustomTrafficReset 插件已卸载');
    }
}