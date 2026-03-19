<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Start extends Telegram {
    public $command = '/start';
    public $description = 'telegram机器人初始化';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $telegramService = $this->telegramService;
        $text = "/start 显示所有可用指令\n /bind+空格+订阅链接，将telegram绑定至账户\n /traffic 获取当前使用流量 \n /getlatesturl 获取网站最新网址 \n /unbind 解绑telegram账户";
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
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
}
