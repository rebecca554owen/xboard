<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StatisticalService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoBan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autoban:traffic {--limit= : Daily traffic limit in GB} {--unban : Unban all users}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动封禁超流量用户';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $startTime = microtime(true);
        $limitGB = $this->option('limit');
        $isUnbanMode = $this->option('unban');

        // 如果指定了 --unban 参数，则执行每日解禁操作
        if ($isUnbanMode) {
            $result = $this->unbanAllUsers();
            if ($result === 1) {
                return 1; // 错误处理已包含在 unbanAllUsers 方法中
            }
            Log::info("自动解禁完成");
            return 0;
        }

        // 设置默认流量限制为 300GB
        if (!$limitGB) {
            $limitGB = 300; // 默认300GB
        }

        // 验证流量限制参数
        if (!is_numeric($limitGB) || $limitGB <= 0) {
            Log::error('流量限制参数错误，必须是正数');
            return 1;
        }

        // 将GB转换为字节（1GB = 1024*1024*1024字节）
        $limitBytes = $limitGB * 1024 * 1024 * 1024;

        // 获取今天0点的时间戳
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayEnd = strtotime(date('Y-m-d 23:59:59'));

        // 使用 StatisticalService 从 Redis 获取流量统计
        $statisticalService = new StatisticalService();
        $statisticalService->setStartAt($todayStart);
        $statisticalService->setEndAt($todayEnd);

        // 从 Redis 获取用户流量统计数据
        $rawTrafficStats = $statisticalService->getStatUser();

        // 使用 UserService 验证用户是否可用，并过滤流量数据
        $userService = new UserService();
        $trafficStats = collect();

        foreach ($rawTrafficStats as $stat) {
            $user = User::find($stat['user_id']);

            // 使用 UserService 的 isAvailable 方法验证用户状态
            if (!$user || !$userService->isAvailable($user)) {
                continue;
            }

            $totalTraffic = $stat['u'] + $stat['d'];
            $trafficStats->push((object)[
                'user_id' => $stat['user_id'],
                'total_traffic' => $totalTraffic,
                'user' => $user
            ]);
        }

        $bannedCount = 0;
        $bannedUserIds = [];

        foreach ($trafficStats as $stat) {
            if ($stat->total_traffic > $limitBytes) {
                // 记录需要封禁的用户ID
                $bannedUserIds[] = $stat->user_id;
                Log::warning("用户流量超限", [
                    'user_id' => $stat->user_id,
                    'traffic_used' => $stat->total_traffic,
                    'traffic_used_human' => Helper::trafficConvert($stat->total_traffic),
                    'traffic_limit' => $limitBytes
                ]);
            }
        }

        // 批量封禁用户
        if (!empty($bannedUserIds)) {
            $bannedCount = count($bannedUserIds);

            // 使用数据库事务确保数据一致性
            DB::beginTransaction();

            try {
                // 批量更新用户状态 - 设置 banned 为 true 并在 remarks 中添加自动封禁标识
                $updatedCount = User::whereIn('id', $bannedUserIds)
                    ->update([
                        'banned' => true,
                        'remarks' => DB::raw("IF(remarks IS NULL OR remarks = '', '自动流量封禁', CONCAT(remarks, '; 自动流量封禁'))")
                    ]);

                // 提交事务
                DB::commit();

                // 记录被封禁的用户信息（使用统计数据中保存的用户对象）
                foreach ($trafficStats as $stat) {
                    if (in_array($stat->user_id, $bannedUserIds)) {
                        Log::info("用户流量超限封禁", [
                            'user_id' => $stat->user_id,
                            'user_email' => $stat->user->email,
                            'traffic_used' => Helper::trafficConvert($stat->total_traffic),
                            'traffic_limit' => $limitGB . 'GB'
                        ]);
                    }
                }

                Log::info("流量封禁完成", ['封禁用户数' => $updatedCount]);
            } catch (\Exception $e) {
                // 回滚事务
                DB::rollBack();
                Log::error("封禁操作失败", ['error' => $e->getMessage()]);
                return 1;
            }
        } else {
            Log::info("无用户需要封禁");
        }
    }

    /**
     * 解禁所有用户
     */
    private function unbanAllUsers()
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
                Log::info("自动解禁完成", ['解禁用户数' => $updatedCount]);
            } else {
                Log::info("无自动封禁用户需要解禁");
            }
        } catch (\Exception $e) {
            // 回滚事务
            DB::rollBack();
            Log::error("解禁操作失败", ['error' => $e->getMessage()]);
            return 1;
        }
    }

}