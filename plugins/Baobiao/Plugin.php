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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const RANK_LIST_LIMIT = 10;
    private const MAX_COMMAND_DAYS = 30;

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
        $days = isset($message->args[0]) ? intval($message->args[0]) : 0;

        return min(self::MAX_COMMAND_DAYS, max(0, $days));
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
        $userStats = $this->calculateUserStats($startAt, $endAt);
        $paymentStats = $this->calculatePaymentStats($startAt, $endAt);

        $report = $this->buildReportText($periodLabel, $userStats, $orderStats, $paymentStats, $startAt, $endAt);

        return [
            'report' => $report,
            'total_amount' => $orderStats['paid_total'] / 100,
            'has_data' => ($orderStats['paid_total'] / 100) > 0 || $paymentStats->isNotEmpty()
        ];
    }

    private function calculateOrderStats(int $startAt, int $endAt): array
    {
        $paidOrderQuery = $this->buildPaidOrderQuery($startAt, $endAt);
        $paidTotal = (int) (clone $paidOrderQuery)->sum('total_amount');
        $typeStats = (clone $paidOrderQuery)
            ->selectRaw('type, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as order_amount')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $newStats = $typeStats->get((string) Order::TYPE_NEW_PURCHASE);
        $renewStats = $typeStats->get((string) Order::TYPE_RENEWAL);
        $upgradeStats = $typeStats->get((string) Order::TYPE_UPGRADE);

        return [
            'paid_total' => $paidTotal,
            'new_order_count' => (int) ($newStats->order_count ?? 0),
            'renew_order_count' => (int) ($renewStats->order_count ?? 0),
            'upgrade_order_count' => (int) ($upgradeStats->order_count ?? 0),
            'new_order_amount' => ((int) ($newStats->order_amount ?? 0)) / 100,
            'renew_order_amount' => ((int) ($renewStats->order_amount ?? 0)) / 100,
            'upgrade_order_amount' => ((int) ($upgradeStats->order_amount ?? 0)) / 100,
        ];
    }

    private function calculateUserStats(int $startAt, int $endAt): array
    {
        $newUsers = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();

        $expiredUsersWithoutRenew = User::where('expired_at', '>=', $startAt)
            ->where('expired_at', '<', $endAt)
            ->count();

        $paidOrderQuery = $this->buildPaidOrderQuery($startAt, $endAt);
        $renewUserCount = (clone $paidOrderQuery)
            ->where('type', Order::TYPE_RENEWAL)
            ->select('user_id')
            ->distinct()
            ->count('user_id');

        $upgradeUserCount = (clone $paidOrderQuery)
            ->where('type', Order::TYPE_UPGRADE)
            ->select('user_id')
            ->distinct()
            ->count('user_id');

        $renewedUsers = (clone $paidOrderQuery)
            ->whereIn('type', [Order::TYPE_RENEWAL, Order::TYPE_UPGRADE])
            ->select('user_id')
            ->distinct()
            ->count('user_id');

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

    private function calculatePaymentStats(int $startAt, int $endAt)
    {
        $paymentStats = collect();
        $paidOrderQuery = $this->buildPaidOrderQuery($startAt, $endAt);
        $paymentOrderStats = (clone $paidOrderQuery)
            ->selectRaw('payment_id, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as order_amount')
            ->groupBy('payment_id')
            ->get();

        $paymentIds = $paymentOrderStats->pluck('payment_id')->filter()->unique()->toArray();
        $payments = Payment::whereIn('id', $paymentIds)
            ->where('enable', 1)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $statsByPaymentId = [];
        $nullPaymentStat = null;
        foreach ($paymentOrderStats as $stat) {
            if ($stat->payment_id === null) {
                $nullPaymentStat = $stat;
                continue;
            }

            $statsByPaymentId[(string) $stat->payment_id] = $stat;
        }

        foreach ($payments as $paymentId => $payment) {
            $stat = $statsByPaymentId[(string) $paymentId] ?? null;
            if ($stat) {
                $paymentStats->push([
                    'name' => $payment->name,
                    'count' => (int) $stat->order_count,
                    'amount' => ((int) $stat->order_amount) / 100
                ]);
            }
        }

        if ($nullPaymentStat) {
            $paymentStats->push([
                'name' => '未关联支付渠道',
                'count' => (int) $nullPaymentStat->order_count,
                'amount' => ((int) $nullPaymentStat->order_amount) / 100
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
            $manualOrderStat = Order::where('callback_no', 'manual_operation')
                ->where('created_at', '>=', $startAt)
                ->where('created_at', '<', $endAt)
                ->whereNotIn('status', [0, 2])
                ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as order_amount')
                ->first();
            $manualOrderCount = (int) ($manualOrderStat->order_count ?? 0);
            $totalManualAmount = ((int) ($manualOrderStat->order_amount ?? 0)) / 100;
            if ($totalManualAmount > 0) {
                $report[] = "   ▸ 手动操作：{$manualOrderCount} 笔（{$totalManualAmount} 元）";
            }
        }

        $report[] = "\n══════════════════════════";
        $report[] = "💰 全部收入总计：".number_format($orderStats['paid_total'] / 100, 2)." 元";

        return $report;
    }

    private function buildPaidOrderQuery(int $startAt, int $endAt): Builder
    {
        return Order::query()
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [0, 2]);
    }

}
