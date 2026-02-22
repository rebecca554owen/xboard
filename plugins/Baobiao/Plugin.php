<?php

namespace Plugin\Baobiao;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\StatisticalService;
use App\Services\TelegramService;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const RANK_LIST_LIMIT = 10;

    private TelegramService $telegramService;

    public function boot(): void
    {
        $this->telegramService = new TelegramService();

        // 注册Telegram命令
        $this->filter('telegram.bot.commands', function ($commands) {
            $commands[] = [
                'command' => '/day',
                'description' => '查询统计报表'
            ];
            $commands[] = [
                'command' => '/top',
                'description' => '查询用户流量排行'
            ];
            $commands[] = [
                'command' => '/tops',
                'description' => '查询服务器流量排行'
            ];
            return $commands;
        });

        // 注册Telegram命令处理器
        $this->filter('telegram.message.handle', function ($handled, $data) {
            if ($handled) return $handled;

            list($msg) = $data;
            if ($msg->message_type === 'message' && in_array($msg->command, ['/day', '/top', '/tops'])) {
                $this->handleTelegramCommand($msg);
                return true;
            }

            return false;
        });
    }

    public function schedule(Schedule $schedule): void
    {
        // 注册定时任务
        $schedule->call(function () {
            if ($this->getConfig('enable_auto_report', true)) {
                $this->sendDailyReport();
            }
        })->dailyAt($this->getConfig('report_time', '09:00'))->name('baobiao_auto_report')->onOneServer();
    }

    private function handleTelegramCommand($message): void
    {
        // 检查用户权限
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user || (!$user->is_admin && !$user->is_staff)) {
            return;
        }

        try {
            switch ($message->command) {
                case '/day':
                    $this->handleDayCommand($message);
                    break;
                case '/top':
                    $this->handleTopCommand($message);
                    break;
                case '/tops':
                    $this->handleTopsCommand($message);
                    break;
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($message->chat_id, "❌ 命令执行失败：" . $e->getMessage());
        }
    }

    private function handleDayCommand($message): void
    {
        // 获取天数参数，默认为0（当天）
        $days = isset($message->args[0]) ? intval($message->args[0]) : 0;
        $days = max(0, $days);

        $result = $this->generateReport($days);

        if ($result['has_data']) {
            $this->telegramService->sendMessage($message->chat_id, implode("\n", $result['report']), 'markdown');
        } else {
            $reportDays = $days === 0 ? '当天' : "{$days}天";
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}无运营数据", 'markdown');
        }
    }

    private function handleTopCommand($message): void
    {
        // 获取天数参数，默认为0（当天）
        $days = isset($message->args[0]) ? intval($message->args[0]) : 0;
        $days = max(0, $days);

        $limit = self::RANK_LIST_LIMIT;

        // 获取时间范围
        $timeRange = $this->getTimeRange($days);
        $statService = new StatisticalService();
        $statService->setStartAt($timeRange['startAt']);
        $statService->setEndAt($timeRange['endAt']);

        $userRank = $statService->getRanking('user_consumption_rank', $limit);

        if (empty($userRank)) {
            $reportDays = $days === 0 ? '今日' : ($days === 1 ? '昨日' : "{$days}天内");
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}暂无用户流量数据", 'markdown');
            return;
        }

        $reportDays = $days === 0 ? '今日' : ($days === 1 ? '昨日' : "{$days}天内");
        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $report = [
            "📊 用户流量排行",
            "时段：{$periodLabel}",
            "══════════════════════════",
        ];

        foreach ($userRank as $index => $user) {
            $rank = $index + 1;
            $total = Helper::trafficConvert($user->total);
            // 使用Markdown包裹邮箱，方便复制
            $report[] = "{$rank}. `{$user->email}`：{$total}";
        }

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
    }

    private function handleTopsCommand($message): void
    {
        // 获取天数参数，默认为0（当天）
        $days = isset($message->args[0]) ? intval($message->args[0]) : 0;
        $days = max(0, $days);

        $limit = self::RANK_LIST_LIMIT;

        // 获取时间范围
        $timeRange = $this->getTimeRange($days);
        $statService = new StatisticalService();
        $serverRank = $this->resolveServerTrafficRank($statService, $days, $limit, $timeRange);

        if ($serverRank->isEmpty()) {
            $reportDays = $days === 0 ? '今日' : ($days === 1 ? '昨日' : "{$days}天内");
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}暂无服务器流量数据", 'markdown');
            return;
        }

        $reportDays = $days === 0 ? '今日' : ($days === 1 ? '昨日' : "{$days}天内");
        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $report = [
            "📊 服务器流量排行",
            "时段：{$periodLabel}",
            "══════════════════════════",
        ];

        // 获取服务器名称
        $serverIds = $serverRank->pluck('server_id')->unique()->toArray();
        $servers = Server::whereIn('id', $serverIds)->get()->keyBy('id');

        foreach ($serverRank as $index => $server) {
            $rank = $index + 1;
            // 使用原始字节数进行计算，确保准确性
            $totalFormatted = Helper::trafficConvert($server->total);

            // 获取服务器名称
            $serverName = isset($servers[$server->server_id]) ? $servers[$server->server_id]->name : "未知节点";

            // 使用Markdown包裹服务器名称和类型，方便复制
            $report[] = "{$rank}. `{$serverName}` ({$server->server_type}) 节点：{$totalFormatted}";
        }

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
    }

    private function resolveServerTrafficRank(StatisticalService $statService, int $days, int $limit, array $timeRange)
    {
        if ($days === 0) {
            $statService->setStartAt($timeRange['startAt']);
            $statService->setEndAt($timeRange['endAt']);

            return collect($statService->getStatServer())
                ->map(function ($stat) {
                    $upload = (int) round($stat['u'] ?? 0);
                    $download = (int) round($stat['d'] ?? 0);
                    return (object) [
                        'server_id' => (int) ($stat['server_id'] ?? 0),
                        'server_type' => (string) ($stat['server_type'] ?? ''),
                        'u' => $upload,
                        'd' => $download,
                        'total' => $upload + $download,
                    ];
                })
                ->sortByDesc('total')
                ->take($limit)
                ->values();
        }

        $rawRank = $days === 1
            ? StatisticalService::getServerRank('yesterday')
            : StatisticalService::getServerRank($timeRange['startAt'], $timeRange['endAt']);

        return collect($rawRank)
            ->map(function ($stat) {
                $upload = (int) round($stat['u'] ?? 0);
                $download = (int) round($stat['d'] ?? 0);
                $total = (int) round($stat['total'] ?? ($upload + $download));
                return (object) [
                    'server_id' => (int) ($stat['server_id'] ?? 0),
                    'server_type' => (string) ($stat['server_type'] ?? ''),
                    'u' => $upload,
                    'd' => $download,
                    'total' => $total,
                ];
            })
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }

    private function sendDailyReport(): void
    {
        // 生成昨日报表
        $result = $this->generateReport(1);

        // 只有在有收入的情况下发送消息
        if ($result['has_data']) {
            $this->telegramService->sendMessageWithAdmin(implode("\n", $result['report']));
            Log::info('昨日运营报表发送成功');
        } else {
            Log::info('昨日无收入数据，跳过发送报表');
        }
    }

    private function getTimeRange(int $days = 0): array
    {
        // 与 StatController 一致的时间处理逻辑
        $startAt = 0;
        $endAt = strtotime('tomorrow');

        if ($days === 0) {
            // 当天报表 - 与 StatController 保持一致
            $startAt = strtotime('today');
            $endAt = strtotime('tomorrow');
        } elseif ($days === 1) {
            // 昨日报表 - 与 StatController 保持一致
            $todayStart = strtotime('today');
            $startAt = strtotime('-1 day', $todayStart);
            $endAt = $todayStart;
        } else {
            // 自定义时间段报表 - 计算从N天前的开始到当前时间
            $startAt = strtotime("-{$days} days", strtotime('today'));
            $endAt = time();
        }

        return [
            'startAt' => $startAt,
            'endAt' => $endAt
        ];
    }

    private function formatTimeRangeLabel(array $timeRange): string
    {
        $start = date('Y-m-d H:i', $timeRange['startAt']);
        $end = date('Y-m-d H:i', $timeRange['endAt']);

        return "{$start} ~ {$end}";
    }

    private function generateReport(int $days = 0): array
    {
        $timeRange = $this->getTimeRange($days);
        $startAt = $timeRange['startAt'];
        $endAt = $timeRange['endAt'];

        $periodLabel = $this->formatTimeRangeLabel($timeRange);

        // 获取统计数据 - 与 StatController 的 getStats 方法保持一致
        // 订单相关统计
        $newOrders = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->get();

        $paidTotal = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // 用户统计
        $newUsers = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();

        $expiredUsersWithoutRenew = User::where('expired_at', '>=', $startAt)
            ->where('expired_at', '<', $endAt)
            ->count();

        // 获取已支付订单的分类统计
        $paidOrders = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->get();

        $newOrderCount = $paidOrders->where('type', Order::TYPE_NEW_PURCHASE)->count();
        $renewOrderCount = $paidOrders->where('type', Order::TYPE_RENEWAL)->count();
        $upgradeOrderCount = $paidOrders->where('type', Order::TYPE_UPGRADE)->count();

        $newOrderAmount = $paidOrders->where('type', Order::TYPE_NEW_PURCHASE)->sum('total_amount') / 100;
        $renewOrderAmount = $paidOrders->where('type', Order::TYPE_RENEWAL)->sum('total_amount') / 100;
        $upgradeOrderAmount = $paidOrders->where('type', Order::TYPE_UPGRADE)->sum('total_amount') / 100;


        // 支付渠道统计 - 与 StatController 保持一致
        $paymentStats = collect();
        $allPayments = Payment::where('enable', 1)->orderBy('name')->get();
        foreach ($allPayments as $payment) {
            $orders = Order::where('payment_id', $payment->id)
                ->where('created_at', '>=', $startAt)
                ->where('created_at', '<', $endAt)
                ->whereNotIn('status', [0, 2])
                ->get();
            if ($orders->isNotEmpty()) {
                $paymentStats->push([
                    'name' => $payment->name,
                    'count' => $orders->count(),
                    'amount' => $orders->sum('total_amount') / 100
                ]);
            }
        }

        // 续费率计算（按用户去重）
        $renewOrderUsers = $paidOrders->where('type', Order::TYPE_RENEWAL)->pluck('user_id');
        $upgradeOrderUsers = $paidOrders->where('type', Order::TYPE_UPGRADE)->pluck('user_id');

        $renewUserCount = $renewOrderUsers->unique()->count();
        $upgradeUserCount = $upgradeOrderUsers->unique()->count();
        $renewedUsers = $renewOrderUsers->merge($upgradeOrderUsers)->unique()->count();

        $expiredUsers = $expiredUsersWithoutRenew + $renewedUsers;
        $unrenewed = $expiredUsersWithoutRenew;
        $renewRate = $expiredUsers ? round(($renewedUsers / $expiredUsers) * 100, 2) : 0;

        // 构建报表 - 与 StatController 保持一致的数据结构
        $report = [
            "📊 运营报表",
            "时段：{$periodLabel}",
            "══════════════════════════",

            "1️⃣ 用户统计：",
            "   ┌ 新增用户：{$newUsers}",
            "   ├ 到期用户：{$expiredUsers}",
            "   ├ 续费用户：{$renewUserCount}",
            "   ├ 升级用户：{$upgradeUserCount}",
            "   ├ 未续费用户：{$unrenewed}",
            "   └ 续费率：{$renewRate}%\n",

            "2️⃣ 订单统计：",
            "   ┌ 新购订单：{$newOrderCount} 个（" . number_format($newOrderAmount, 2) . " 元）",
            "   ├ 续费订单：{$renewOrderCount} 个（" . number_format($renewOrderAmount, 2) . " 元）",
            "   └ 升级订单：{$upgradeOrderCount} 个（" . number_format($upgradeOrderAmount, 2) . " 元）\n",

            "3️⃣ 收入统计：",
            "   ┌ 总收入：".number_format($paidTotal / 100, 2)." 元",
            "   └ 支付渠道："
        ];

        // 添加支付渠道明细
        if ($paymentStats->isNotEmpty()) {
            $report[] = $paymentStats->map(fn($p) => "   ▸ {$p['name']}：{$p['count']} 笔（{$p['amount']} 元）")->join("\n");
        } else {
            // 处理手动操作收款情况
            $manualOrders = Order::where('callback_no', 'manual_operation')
                ->where('created_at', '>=', $startAt)
                ->where('created_at', '<', $endAt)
                ->whereNotIn('status', [0, 2])
                ->get();
            $totalManualAmount = $manualOrders->sum('total_amount') / 100;
            if ($totalManualAmount > 0) {
                $report[] = "   ▸ 手动操作：{$manualOrders->count()} 笔（{$totalManualAmount} 元）";
            }
        }

        $report[] = "\n══════════════════════════";
        $report[] = "💰 全部收入总计：".number_format($paidTotal / 100, 2)." 元";

        return [
            'report' => $report,
            'total_amount' => $paidTotal / 100,
            'has_data' => ($paidTotal / 100) > 0 || $paymentStats->isNotEmpty()
        ];
    }

}
