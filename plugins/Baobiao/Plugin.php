<?php

namespace Plugin\Baobiao;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Server;
use App\Models\StatServer;
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
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user || (!$user->is_admin && !$user->is_staff)) return;

        $this->executeTelegramCommand($message, $message->command);
    }

    private function executeTelegramCommand($message, string $command): void
    {
        try {
            switch ($command) {
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
            $this->telegramService->sendMessage($message->chat_id, "命令执行失败：" . $e->getMessage());
        }
    }

    private function getCommandDays($message): int
    {
        return max(0, isset($message->args[0]) ? intval($message->args[0]) : 0);
    }

    private function formatDaysLabel(int $days, string $today, string $yesterday, string $daysFormat): string
    {
        return $days === 0 ? $today : ($days === 1 ? $yesterday : $daysFormat);
    }

    private function createStatisticalService(int $days, int $limit): array
    {
        $timeRange = $this->getTimeRange($days);
        $statService = new StatisticalService();
        $statService->setStartAt($timeRange['startAt']);
        $statService->setEndAt($timeRange['endAt']);

        return [$statService, $timeRange];
    }

    private function handleDayCommand($message): void
    {
        $days = $this->getCommandDays($message);
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
        $days = $this->getCommandDays($message);
        list($statService, $timeRange) = $this->createStatisticalService($days, self::RANK_LIST_LIMIT);

        $userRank = $statService->getRanking('user_consumption_rank', self::RANK_LIST_LIMIT);

        if (empty($userRank)) {
            $reportDays = $this->formatDaysLabel($days, '今日', '昨日', "{$days}天内");
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}暂无用户流量数据", 'markdown');
            return;
        }

        $reportDays = $this->formatDaysLabel($days, '今日', '昨日', "{$days}天内");
        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $report = [
            "📊 用户流量排行",
            "时段：{$periodLabel}",
            "══════════════════════════",
        ];

        foreach ($userRank as $index => $user) {
            $rank = $index + 1;
            $total = Helper::trafficConvert($user->total);
            $report[] = "{$rank}. `{$user->email}`：{$total}";
        }

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
    }

    private function handleTopsCommand($message): void
    {
        $days = $this->getCommandDays($message);
        list($statService, $timeRange) = $this->createStatisticalService($days, self::RANK_LIST_LIMIT);
        $serverRank = $this->resolveServerTrafficRank($statService, $days, self::RANK_LIST_LIMIT, $timeRange);

        if ($serverRank->isEmpty()) {
            $reportDays = $this->formatDaysLabel($days, '今日', '昨日', "{$days}天内");
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}暂无服务器流量数据", 'markdown');
            return;
        }

        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $report = [
            "📊 服务器流量排行",
            "时段：{$periodLabel}",
            "══════════════════════════",
        ];

        $serverIds = $serverRank->pluck('server_id')->unique()->toArray();
        $servers = Server::whereIn('id', $serverIds)->get()->keyBy('id');

        foreach ($serverRank as $index => $server) {
            $rank = $index + 1;
            $totalFormatted = Helper::trafficConvert($server->total);
            $serverName = isset($servers[$server->server_id]) ? $servers[$server->server_id]->name : "未知节点";
            $report[] = "{$rank}. `{$serverName}` ({$server->server_type}) 节点：{$totalFormatted}";
        }

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
    }

    private function resolveServerTrafficRank(StatisticalService $statService, int $days, int $limit, array $timeRange)
    {
        if ($days === 0) {
            // 今日流量：直接从 StatServer 表查询今日数据
            // 因为 Redis 中的数据可能不存在或不是实时更新的
            $todayStart = $timeRange['startAt']; // strtotime('today')
            $todayEnd = $timeRange['endAt'];     // strtotime('tomorrow')

            $rawData = StatServer::selectRaw('
                    server_id,
                    server_type,
                    SUM(u) as u,
                    SUM(d) as d,
                    SUM(u + d) as total
                ')
                ->where('record_at', '>=', $todayStart)
                ->where('record_at', '<', $todayEnd)
                ->where('record_type', 'd')
                ->groupBy('server_id', 'server_type')
                ->orderBy('total', 'DESC')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    return [
                        'server_id' => $item->server_id,
                        'server_type' => $item->server_type,
                        'u' => (int) $item->u,
                        'd' => (int) $item->d,
                        'total' => (int) $item->total,
                    ];
                })
                ->toArray();
        } else {
            // 历史流量：从数据库中获取
            $rawData = $days === 1
                ? StatisticalService::getServerRank('yesterday')
                : StatisticalService::getServerRank($timeRange['startAt'], $timeRange['endAt']);
        }

        return collect($rawData)
            ->map(fn($stat) => $this->mapServerStat($stat))
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }

    private function mapServerStat(array $stat): object
    {
        $upload = (float) ($stat['u'] ?? 0);
        $download = (float) ($stat['d'] ?? 0);
        $total = isset($stat['total'])
            ? (int) round($stat['total'])
            : (int) round($upload + $download);

        return (object) [
            'server_id' => (int) ($stat['server_id'] ?? 0),
            'server_type' => (string) ($stat['server_type'] ?? ''),
            'u' => (int) round($upload),
            'd' => (int) round($download),
            'total' => $total,
        ];
    }

    private function sendDailyReport(): void
    {
        $result = $this->generateReport(1);

        if ($result['has_data']) {
            $this->telegramService->sendMessageWithAdmin(implode("\n", $result['report']));
            Log::info('昨日运营报表发送成功');
        } else {
            Log::info('昨日无收入数据，跳过发送报表');
        }
    }

    private function getTimeRange(int $days = 0): array
    {
        $todayStart = strtotime('today');
        $tomorrow = strtotime('tomorrow');

        if ($days === 0) {
            return ['startAt' => $todayStart, 'endAt' => $tomorrow];
        }

        if ($days === 1) {
            return ['startAt' => strtotime('-1 day', $todayStart), 'endAt' => $todayStart];
        }

        return ['startAt' => strtotime("-{$days} days", $todayStart), 'endAt' => time()];
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
        $orderStats = $this->calculateOrderStats($startAt, $endAt);
        $userStats = $this->calculateUserStats($startAt, $endAt, $orderStats['paid_orders']);
        $paymentStats = $this->calculatePaymentStats($startAt, $endAt, $orderStats['paid_orders']);

        $report = $this->buildReportText($periodLabel, $userStats, $orderStats, $paymentStats, $startAt, $endAt);

        return [
            'report' => $report,
            'total_amount' => $orderStats['paid_total'] / 100,
            'has_data' => ($orderStats['paid_total'] / 100) > 0 || $paymentStats->isNotEmpty()
        ];
    }

    private function calculateOrderStats(int $startAt, int $endAt): array
    {
        // Performance: Single query with only necessary fields
        $paidOrders = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->get(['id', 'user_id', 'type', 'total_amount', 'payment_id']);

        // Performance: Calculate totals once using collection aggregation
        $paidTotal = $paidOrders->sum('total_amount');

        // Performance: Group orders by type to avoid multiple collection iterations
        $ordersByType = $paidOrders->groupBy('type');

        $newOrders = $ordersByType->get(Order::TYPE_NEW_PURCHASE, collect());
        $renewOrders = $ordersByType->get(Order::TYPE_RENEWAL, collect());
        $upgradeOrders = $ordersByType->get(Order::TYPE_UPGRADE, collect());

        return [
            'paid_orders' => $paidOrders,
            'paid_total' => $paidTotal,
            'new_order_count' => $newOrders->count(),
            'renew_order_count' => $renewOrders->count(),
            'upgrade_order_count' => $upgradeOrders->count(),
            'new_order_amount' => $newOrders->sum('total_amount') / 100,
            'renew_order_amount' => $renewOrders->sum('total_amount') / 100,
            'upgrade_order_amount' => $upgradeOrders->sum('total_amount') / 100,
        ];
    }

    private function calculateUserStats(int $startAt, int $endAt, $paidOrders): array
    {
        $newUsers = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();

        $expiredUsersWithoutRenew = User::where('expired_at', '>=', $startAt)
            ->where('expired_at', '<', $endAt)
            ->count();

        $renewOrderUsers = $paidOrders->where('type', Order::TYPE_RENEWAL)->pluck('user_id');
        $upgradeOrderUsers = $paidOrders->where('type', Order::TYPE_UPGRADE)->pluck('user_id');

        $renewUserCount = $renewOrderUsers->unique()->count();
        $upgradeUserCount = $upgradeOrderUsers->unique()->count();
        $renewedUsers = $renewOrderUsers->merge($upgradeOrderUsers)->unique()->count();

        $renewRate = $this->calculateRenewRate($expiredUsersWithoutRenew, $renewedUsers);
        $expiredUsers = $expiredUsersWithoutRenew + $renewedUsers;

        return [
            'new_users' => $newUsers,
            'expired_users' => $expiredUsers,
            'renew_user_count' => $renewUserCount,
            'upgrade_user_count' => $upgradeUserCount,
            'unrenewed' => $expiredUsersWithoutRenew,
            'renew_rate' => $renewRate,
        ];
    }

    private function calculateRenewRate(int $expiredUsersWithoutRenew, int $renewedUsers): float
    {
        $expiredUsers = $expiredUsersWithoutRenew + $renewedUsers;
        return $expiredUsers ? round(($renewedUsers / $expiredUsers) * 100, 2) : 0;
    }

    private function calculatePaymentStats(int $startAt, int $endAt, $paidOrders)
    {
        $paymentStats = collect();

        $allOrders = $paidOrders->groupBy('payment_id');

        $paymentIds = $allOrders->keys()->filter()->unique()->toArray();
        $payments = Payment::whereIn('id', $paymentIds)
            ->where('enable', 1)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        foreach ($payments as $paymentId => $payment) {
            $orders = $allOrders->get($paymentId, collect());
            if ($orders->isNotEmpty()) {
                $paymentStats->push([
                    'name' => $payment->name,
                    'count' => $orders->count(),
                    'amount' => $orders->sum('total_amount') / 100
                ]);
            }
        }

        $nullPaymentOrders = $allOrders->get(null, collect());
        if ($nullPaymentOrders->isNotEmpty()) {
            $paymentStats->push([
                'name' => '未关联支付渠道',
                'count' => $nullPaymentOrders->count(),
                'amount' => $nullPaymentOrders->sum('total_amount') / 100
            ]);
        }

        return $paymentStats;
    }

    private function buildReportText(
        string $periodLabel,
        array $userStats,
        array $orderStats,
        $paymentStats,
        int $startAt,
        int $endAt
    ): array {
        $report = [
            "📊 运营报表",
            "时段：{$periodLabel}",
            "══════════════════════════",

            "1️⃣ 用户统计：",
            "   ┌ 新增用户：{$userStats['new_users']}",
            "   ├ 到期用户：{$userStats['expired_users']}",
            "   ├ 续费用户：{$userStats['renew_user_count']}",
            "   ├ 升级用户：{$userStats['upgrade_user_count']}",
            "   ├ 未续费用户：{$userStats['unrenewed']}",
            "   └ 续费率：{$userStats['renew_rate']}%\n",

            "2️⃣ 订单统计：",
            "   ┌ 新购订单：{$orderStats['new_order_count']} 个（" . number_format($orderStats['new_order_amount'], 2) . " 元）",
            "   ├ 续费订单：{$orderStats['renew_order_count']} 个（" . number_format($orderStats['renew_order_amount'], 2) . " 元）",
            "   └ 升级订单：{$orderStats['upgrade_order_count']} 个（" . number_format($orderStats['upgrade_order_amount'], 2) . " 元）\n",

            "3️⃣ 收入统计：",
            "   ┌ 总收入：".number_format($orderStats['paid_total'] / 100, 2)." 元",
            "   └ 支付渠道："
        ];

        if ($paymentStats->isNotEmpty()) {
            $report[] = $paymentStats->map(fn($p) => "   ▸ {$p['name']}：{$p['count']} 笔（{$p['amount']} 元）")->join("\n");
        } else {
            // Performance: Use already fetched orders instead of new query when possible
            // Note: Manual orders require separate query as they're filtered by callback_no
            $manualOrders = Order::where('callback_no', 'manual_operation')
                ->where('created_at', '>=', $startAt)
                ->where('created_at', '<', $endAt)
                ->whereNotIn('status', [0, 2])
                ->get(['id', 'total_amount']);
            $totalManualAmount = $manualOrders->sum('total_amount') / 100;
            if ($totalManualAmount > 0) {
                $report[] = "   ▸ 手动操作：{$manualOrders->count()} 笔（{$totalManualAmount} 元）";
            }
        }

        $report[] = "\n══════════════════════════";
        $report[] = "💰 全部收入总计：".number_format($orderStats['paid_total'] / 100, 2)." 元";

        return $report;
    }

}
