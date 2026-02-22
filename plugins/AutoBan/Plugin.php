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
        // 插件依赖定时任务执行
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
        if ($this->getConfig('enable_auto_unban', true)) {
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
        $interval = max(1, (int) $this->getConfig('scan_interval_minutes', 1));

        if ($interval === 1440) {
            return '0 0 * * *';
        }

        if ($interval === 60) {
            return '0 * * * *';
        }

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

            $validTrafficStats = $this->fetchValidTrafficStats($trafficStats);

            if ($validTrafficStats->isEmpty()) {
                Log::info('AutoBan 插件：无有效用户流量数据。');

                return;
            }

            $overLimitUsers = $this->banOverLimitUsers($validTrafficStats, $limitBytes);

            if ($overLimitUsers > 0) {
                $this->logBanResults($validTrafficStats, $limitBytes, $overLimitUsers, $startTime);
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

    /**
     * 获取有效的流量统计数据（过滤不可用用户）
     *
     * 性能优化：使用批量查询替代 N+1 查询问题
     * - 批量获取所有用户数据（1次查询）
     * - 在内存中进行过滤和匹配
     */
    private function fetchValidTrafficStats(Collection $trafficStats): Collection
    {
        if ($trafficStats->isEmpty()) {
            return collect();
        }

        // 批量查询：一次性获取所有相关用户，避免 N+1 查询
        $userIds = $trafficStats->pluck('user_id')->unique()->filter();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $userService = new UserService();
        $validTrafficStats = collect();

        foreach ($trafficStats as $stat) {
            $user = $users->get($stat['user_id']);

            if (!$user || !$userService->isAvailable($user)) {
                continue;
            }

            $validTrafficStats->push([
                'user_id' => $stat['user_id'],
                'total_traffic' => $stat['total_traffic'],
                'user' => $user
            ]);
        }

        return $validTrafficStats;
    }

    /**
     * 封禁超过流量限制的用户
     *
     * 性能优化：使用单条批量 UPDATE 语句替代循环更新
     * - whereIn + update 在一次数据库操作中完成所有更新
     * - 避免了 N+1 更新问题
     */
    private function banOverLimitUsers(Collection $validTrafficStats, int $limitBytes): int
    {
        $overLimitUsers = $validTrafficStats
            ->filter(fn (array $stat) => $stat['total_traffic'] > $limitBytes)
            ->keyBy('user_id');

        if ($overLimitUsers->isEmpty()) {
            Log::info('AutoBan 插件：没有用户超过流量限制。');

            return 0;
        }

        $userIds = $overLimitUsers->keys()->all();

        DB::beginTransaction();

        try {
            $updatedCount = User::whereIn('id', $userIds)
                ->update([
                    'banned' => true,
                    'remarks' => DB::raw("IF(remarks IS NULL OR remarks = '', '自动流量封禁', CONCAT(remarks, '; 自动流量封禁'))")
                ]);

            DB::commit();

            return $updatedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AutoBan 插件：封禁操作失败', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 记录封禁结果日志
     */
    private function logBanResults(Collection $validTrafficStats, int $limitBytes, int $updatedCount, float $startTime): void
    {
        $overLimitUsers = $validTrafficStats
            ->filter(fn (array $stat) => $stat['total_traffic'] > $limitBytes)
            ->keyBy('user_id');

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
    }

    /**
     * 解禁所有自动封禁的用户
     *
     * 性能优化：直接使用 update() 返回值
     * - 避免先 count 再 update 的重复查询
     * - 单条 SQL 语句完成解禁和备注清理
     */
    public function unbanAllUsers(): void
    {
        DB::beginTransaction();

        try {
            $updatedCount = User::where('banned', true)
                ->where('remarks', 'LIKE', '%自动流量封禁%')
                ->update([
                    'banned' => false,
                    'remarks' => DB::raw("REPLACE(REPLACE(remarks, '; 自动流量封禁', ''), '自动流量封禁', '')")
                ]);

            DB::commit();

            if ($updatedCount > 0) {
                Log::info(sprintf('AutoBan 插件：已解禁 %d 个自动封禁用户。', $updatedCount));
            } else {
                Log::info('AutoBan 插件：无自动封禁用户需要解禁。');
            }
        } catch (\Throwable $exception) {
            DB::rollBack();
            Log::error('AutoBan 插件：解禁操作失败。', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 获取今日用户流量统计
     */
    private function fetchTodayTraffic(): Collection
    {
        $now = Carbon::now();

        $service = new StatisticalService();
        $service->setStartAt($now->copy()->startOfDay()->timestamp);
        $service->setEndAt($now->copy()->endOfDay()->timestamp);

        $rawStats = $service->getStatUser() ?? [];

        return collect($rawStats)
            ->map(function (array $stat): array {
                return [
                    'user_id' => $stat['user_id'],
                    'total_traffic' => ($stat['u'] ?? 0) + ($stat['d'] ?? 0),
                ];
            })
            ->groupBy('user_id')
            ->map(function (Collection $stats): array {
                return [
                    'user_id' => $stats->first()['user_id'],
                    'total_traffic' => $stats->sum('total_traffic'),
                ];
            })
            ->values();
    }
}
