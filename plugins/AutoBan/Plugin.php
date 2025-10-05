<?php

namespace Plugin\AutoBan;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\StatisticalService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const TRAFFIC_LOCK_KEY = 'plugin:auto_ban:traffic_lock';
    private const LOCK_TTL_SECONDS = 300;

    public function boot(): void
    {
        // 该插件依赖定时任务，不注册运行时钩子
    }

    public function schedule(Schedule $schedule): void
    {
        // 自动封禁任务
        $schedule->call(function (): void {
            if (!$this->getConfig('enable_auto_ban', true)) {
                return;
            }

            $this->executeAutoBan();
        })->cron($this->getScanCronExpression())->name('auto_ban_scan')->onOneServer();

        // 自动解禁任务
        if ($this->getConfig('enable_auto_unban', false)) {
            $schedule->call(function (): void {
                $this->unbanAllUsers();
            })->dailyAt($this->getConfig('unban_time', '00:00'))->name('auto_ban_unban')->onOneServer();
        }
    }

    /**
     * 根据扫描间隔生成 Cron 表达式
     */
    private function getScanCronExpression(): string
    {
        $interval = (int) $this->getConfig('scan_interval_minutes', 1);

        if ($interval <= 0) {
            $interval = 1;
        }

        // 如果间隔是1440分钟(24小时)，则使用每日定时
        if ($interval === 1440) {
            return '0 0 * * *'; // 每天0点
        }

        // 如果间隔是60分钟(1小时)，则使用每小时
        if ($interval === 60) {
            return '0 * * * *'; // 每小时0分
        }

        // 其他分钟间隔使用 */interval 格式
        return '*/' . $interval . ' * * * *';
    }

    public function executeAutoBan(?float $limitGb = null): void
    {
        $lockAcquired = Cache::add(self::TRAFFIC_LOCK_KEY, 1, self::LOCK_TTL_SECONDS);
        if (!$lockAcquired) {
            Log::info('AutoBan 插件：检测到任务仍在执行，跳过本轮。');

            return;
        }

        $startTime = microtime(true);

        try {
            $limitGb ??= (float) $this->getConfig('daily_limit_gb', 300);
            if ($limitGb <= 0) {
                Log::warning('AutoBan 插件：每日流量上限配置无效，终止执行。');

                return;
            }

            $limitBytes = (int) round($limitGb * 1024 * 1024 * 1024);
            $trafficStats = $this->fetchTodayTraffic();

            if ($trafficStats->isEmpty()) {
                Log::info('AutoBan 插件：今日无流量统计数据。');

                return;
            }

            // 使用 UserService 验证用户是否可用，并过滤流量数据
            $userService = new UserService();
            $validTrafficStats = collect();

            foreach ($trafficStats as $stat) {
                $user = User::find($stat['user_id']);

                // 使用 UserService 的 isAvailable 方法验证用户状态
                if (!$user || !$userService->isAvailable($user)) {
                    continue;
                }

                $validTrafficStats->push([
                    'user_id' => $stat['user_id'],
                    'total_traffic' => $stat['total_traffic'],
                    'user' => $user
                ]);
            }

            $overLimitUsers = $validTrafficStats
                ->filter(fn (array $stat) => $stat['total_traffic'] > $limitBytes)
                ->keyBy('user_id');

            if ($overLimitUsers->isEmpty()) {
                Log::info('AutoBan 插件：没有用户超过流量限制。');

                return;
            }

            $userIds = $overLimitUsers->keys()->all();

            // 使用数据库事务确保数据一致性
            DB::beginTransaction();

            try {
                // 批量更新用户状态 - 设置 banned 为 true 并在 remarks 中添加自动封禁标识
                $updatedCount = User::whereIn('id', $userIds)
                    ->update([
                        'banned' => true,
                        'remarks' => DB::raw("IF(remarks IS NULL OR remarks = '', '自动流量封禁', CONCAT(remarks, '; 自动流量封禁'))")
                    ]);

                // 提交事务
                DB::commit();

                // 记录被封禁的用户信息
                foreach ($overLimitUsers as $userId => $stat) {
                    $user = $stat['user'];
                    $total = Helper::trafficConvert($stat['total_traffic']);
                    $limit = Helper::trafficConvert($limitBytes);

                    Log::warning('AutoBan 插件：用户流量超限封禁', [
                        'user_id' => $userId,
                        'user_email' => $user->email,
                        'traffic_used' => $total,
                        'traffic_limit' => $limit
                    ]);
                }

                Log::info(sprintf(
                    'AutoBan 插件：封禁超限用户 %d 个，耗时 %.2f 秒。',
                    $updatedCount,
                    microtime(true) - $startTime
                ));
            } catch (\Exception $e) {
                // 回滚事务
                DB::rollBack();
                Log::error('AutoBan 插件：封禁操作失败', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        } catch (\Throwable $exception) {
            Log::error('AutoBan 插件：执行失败。', [
                'error' => $exception->getMessage(),
            ]);
        } finally {
            if ($lockAcquired) {
                Cache::forget(self::TRAFFIC_LOCK_KEY);
            }
        }
    }

    public function unbanAllUsers(): void
    {
        // 使用数据库事务确保数据一致性
        DB::beginTransaction();

        try {
            // 只解禁带有"自动流量封禁"标识的用户
            $autoBannedUsers = User::where('banned', true)
                ->where(function($query) {
                    $query->where('remarks', 'LIKE', '%自动流量封禁%')
                          ->orWhere('remarks', '自动流量封禁');
                })
                ->get();
            $autoBannedCount = $autoBannedUsers->count();

            // 批量解禁用户，并移除自动封禁标识
            $updatedCount = User::where('banned', true)
                ->where(function($query) {
                    $query->where('remarks', 'LIKE', '%自动流量封禁%')
                          ->orWhere('remarks', '自动流量封禁');
                })
                ->update([
                    'banned' => false,
                    'remarks' => DB::raw("REPLACE(REPLACE(remarks, '; 自动流量封禁', ''), '自动流量封禁', '')")
                ]);

            // 提交事务
            DB::commit();

            if ($autoBannedCount > 0) {
                Log::info(sprintf('AutoBan 插件：已解禁 %d 个自动封禁用户。', $updatedCount));
            } else {
                Log::info('AutoBan 插件：无自动封禁用户需要解禁。');
            }
        } catch (\Throwable $exception) {
            // 回滚事务
            DB::rollBack();
            Log::error('AutoBan 插件：解禁操作失败。', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 获取今日用户流量统计。
     */
    private function fetchTodayTraffic(): Collection
    {
        $now = Carbon::now();
        $startAt = $now->copy()->startOfDay()->timestamp;
        $endAt = $now->copy()->endOfDay()->timestamp;

        $service = new StatisticalService();
        $service->setStartAt($startAt);
        $service->setEndAt($endAt);

        $rawStats = $service->getStatUser() ?? [];

        return collect($rawStats)->map(static function (array $stat): array {
            $upload = $stat['u'] ?? 0;
            $download = $stat['d'] ?? 0;

            return [
                'user_id' => $stat['user_id'],
                'total_traffic' => $upload + $download,
            ];
        });
    }
}
