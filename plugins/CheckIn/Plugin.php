<?php

namespace Plugin\CheckIn;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class Plugin extends AbstractPlugin
{
    private ?TelegramService $telegramService = null;

    private function telegram(): TelegramService
    {
        return $this->telegramService ??= new TelegramService();
    }

    public function boot(): void
    {
        $this->filter('telegram.bot.commands', function ($commands) {
            $commands[] = [
                'command' => '/checkin',
                'description' => '每日签到获取流量',
            ];
            return $commands;
        });

        $this->filter('telegram.message.handle', function ($handled, $data) {
            if ($handled) {
                return $handled;
            }

            [$msg] = $data;
            if ($msg->message_type !== 'message') {
                return false;
            }

            if (trim($msg->text) !== '/checkin') {
                return false;
            }

            $this->handleCheckIn($msg);
            return true;
        });
    }

    private function handleCheckIn($message): void
    {
        if (!$message->is_private) {
            return;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegram()->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        $tz = config('app.timezone');
        $now = Carbon::now($tz);
        $midnight = Carbon::tomorrow($tz)->startOfDay();
        $ttl = (int) $now->diffInSeconds($midnight);
        $redisKey = $this->buildCheckinKey($user->id);

        if (!Cache::add($redisKey, 1, $ttl)) {
            $remaining = $now->diff($midnight);
            $hint = sprintf('%02d小时%02d分%02d秒', $remaining->h, $remaining->i, $remaining->s);
            $this->telegram()->sendMessage($message->chat_id, "您今天已经签到过了，距离下次签到还有 {$hint}", 'markdown');
            return;
        }

        $minMb = max(0, (int) $this->getConfig('min_traffic_mb', 0));
        $maxMb = max($minMb, (int) $this->getConfig('max_traffic_mb', 1024));
        $addedBytes = rand($minMb, $maxMb) * 1024 * 1024;

        $user->transfer_enable += $addedBytes;
        $user->save();

        $added = Helper::trafficConvert($addedBytes);
        $total = Helper::trafficConvert($user->transfer_enable);

        $this->telegram()->sendMessage(
            $message->chat_id,
            "✅ 签到成功\n———————————————\n本次签到增加流量：`{$added}`\n当前计划流量：`{$total}`",
            'markdown'
        );
    }

    private function buildCheckinKey(int $userId): string
    {
        return "plugin:checkin:{$userId}";
    }
}
