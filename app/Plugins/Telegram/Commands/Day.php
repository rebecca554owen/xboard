<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Day extends Telegram
{
    public $command = '/day';
    public $description = '运营报表';

    public function handle($message, $match = [])
    {
        if (!$this->ensureAuthorized($message)) {
            return;
        }

        $days = $this->resolveDays($message->args);
        $result = $this->generateReport($days);

        if ($result['has_data']) {
            $this->telegramService->sendMessage($message->chat_id, implode("\n", $result['report']), 'markdown');
            return;
        }

        $reportDays = $days === 0 ? '当天' : "{$days}天";
        $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}无运营数据", 'markdown');
    }

    /**
     * 校验是否为管理员或员工的私聊命令。
     */
    protected function ensureAuthorized($message): bool
    {
        if (!$message->is_private) {
            return false;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user || (!$user->is_admin && !$user->is_staff)) {
            $this->telegramService->sendMessage($message->chat_id, '❌ 权限不足，仅管理员和员工可使用此命令');
            return false;
        }

        return true;
    }

    /**
     * 解析命令中的天数参数。
     */
    protected function resolveDays(array $args, int $index = 0): int
    {
        return isset($args[$index]) ? max(0, intval($args[$index])) : 0;
    }

    protected function getTimeRange(int $days = 0): array
    {
        $startAt = 0;
        $endAt = strtotime('tomorrow');

        if ($days === 0) {
            $startAt = strtotime('today');
            $endAt = strtotime('tomorrow');
        } elseif ($days === 1) {
            $todayStart = strtotime('today');
            $startAt = strtotime('-1 day', $todayStart);
            $endAt = $todayStart;
        } else {
            $startAt = strtotime("-{$days} days", strtotime('today'));
            $endAt = time();
        }

        return [
            'startAt' => $startAt,
            'endAt' => $endAt,
        ];
    }

    protected function formatTimeRangeLabel(array $timeRange): string
    {
        $start = date('Y-m-d H:i', $timeRange['startAt']);
        $end = date('Y-m-d H:i', $timeRange['endAt']);

        return "{$start} ~ {$end}";
    }

    protected function generateReport(int $days = 0): array
    {
        $timeRange = $this->getTimeRange($days);
        $startAt = $timeRange['startAt'];
        $endAt = $timeRange['endAt'];
        $periodLabel = $this->formatTimeRangeLabel($timeRange);

        $paidOrders = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->get();

        $paidTotal = $paidOrders->sum('total_amount');

        $newUsers = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();

        $expiredUsersWithoutRenew = User::where('expired_at', '>=', $startAt)
            ->where('expired_at', '<', $endAt)
            ->count();

        $newOrderCount = $paidOrders->where('type', Order::TYPE_NEW_PURCHASE)->count();
        $renewOrderCount = $paidOrders->where('type', Order::TYPE_RENEWAL)->count();
        $upgradeOrderCount = $paidOrders->where('type', Order::TYPE_UPGRADE)->count();

        $newOrderAmount = $paidOrders->where('type', Order::TYPE_NEW_PURCHASE)->sum('total_amount') / 100;
        $renewOrderAmount = $paidOrders->where('type', Order::TYPE_RENEWAL)->sum('total_amount') / 100;
        $upgradeOrderAmount = $paidOrders->where('type', Order::TYPE_UPGRADE)->sum('total_amount') / 100;

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
                    'amount' => $orders->sum('total_amount') / 100,
                ]);
            }
        }

        $renewOrderUsers = $paidOrders->where('type', Order::TYPE_RENEWAL)->pluck('user_id');
        $upgradeOrderUsers = $paidOrders->where('type', Order::TYPE_UPGRADE)->pluck('user_id');

        $renewUserCount = $renewOrderUsers->unique()->count();
        $upgradeUserCount = $upgradeOrderUsers->unique()->count();
        $renewedUsers = $renewOrderUsers->merge($upgradeOrderUsers)->unique()->count();

        $expiredUsers = $expiredUsersWithoutRenew + $renewedUsers;
        $unrenewed = $expiredUsersWithoutRenew;
        $renewRate = $expiredUsers ? round(($renewedUsers / $expiredUsers) * 100, 2) : 0;

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
            "   ┌ 总收入：" . number_format($paidTotal / 100, 2) . " 元",
            "   └ 支付渠道：",
        ];

        if ($paymentStats->isNotEmpty()) {
            $report[] = $paymentStats->map(fn($p) => "   ▸ {$p['name']}：{$p['count']} 笔（{$p['amount']} 元）")->join("\n");
        } else {
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
        $report[] = "💰 全部收入总计：" . number_format($paidTotal / 100, 2) . " 元";

        return [
            'report' => $report,
            'total_amount' => $paidTotal / 100,
            'has_data' => ($paidTotal / 100) > 0 || $paymentStats->isNotEmpty(),
        ];
    }
}
