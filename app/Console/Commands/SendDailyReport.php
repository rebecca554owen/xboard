<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\TelegramService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;

/**
 * 运营报表发送命令
 *
 * 使用示例：
 * - php artisan baobiao:send-report --days=0          # 当天数据
 * - php artisan baobiao:send-report --days=1          # 昨日数据（默认）
 *
 * 定时任务示例：
 * - 当天报表：$schedule->command('baobiao:send-report --days=0')->dailyAt('23:59')
 * - 昨日报表：$schedule->command('baobiao:send-report --days=1')->dailyAt('09:00')
 */
class SendDailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'baobiao:send-report {--days=1 : 统计天数，默认为1（昨日）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '发送运营报表';

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
        $days = (int) $this->option('days');
        $days = max(0, $days);

        $this->info("开始生成 {$days} 天运营报表...");

        try {
            $result = $this->generateReport($days);

            if ($result['has_data']) {
                $telegramService = new TelegramService();
                $telegramService->sendMessageWithAdmin(implode("\n", $result['report']));

                $this->info("运营报表发送成功！");
                Log::info('运营报表发送成功', ['days' => $days, 'total_amount' => $result['total_amount']]);
            } else {
                $this->info("无运营数据，跳过发送报表");
                Log::info('无运营数据，跳过发送报表', ['days' => $days]);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("报表发送失败：" . $e->getMessage());
            Log::error('运营报表发送失败', [
                'days' => $days,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 获取统计时间段
     *
     * @param int $days 统计天数
     *   - 0: 当天数据（从今天0点到当前时间）
     *   - 1: 昨日数据（从昨天0点到今天0点）
     *   - >1: 最近N天数据（从N天前0点到当前时间）
     *
     * @return array 包含 startAt 和 endAt 的时间戳数组
     */
    private function getTimeRange(int $days = 0): array
    {
        $startAt = 0;
        $endAt = strtotime('tomorrow');

        if ($days === 0) {
            // 当天报表：从今天0点到当前时间
            $startAt = strtotime('today');
            $endAt = strtotime('tomorrow');
        } elseif ($days === 1) {
            // 昨日报表：从昨天0点到今天0点
            $todayStart = strtotime('today');
            $startAt = strtotime('-1 day', $todayStart);
            $endAt = $todayStart;
        } else {
            // 自定义时间段报表：从N天前0点到当前时间
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

        // 获取统计数据
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

        // 支付渠道统计
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

        // 构建报表
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