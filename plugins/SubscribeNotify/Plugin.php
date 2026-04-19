<?php

namespace Plugin\SubscribeNotify;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use App\Services\UserService;
use App\Utils\Helper;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('client.subscribe.before', [$this, 'handleSubscribe']);
    }

    public function handleSubscribe(): void
    {
        $request = request();
        $user = $request->user();

        if (!$user) {
            return;
        }

        try {
            $ip = $this->getRealIp($request);
            $userAgent = $request->input('flag') ?? $request->header('User-Agent', '');
            $region = $this->getRegion($ip);

            $inviteEmail = null;
            if ($this->getConfig('show_invite_email', true) && $user->invite_user_id) {
                $inviteUser = User::find($user->invite_user_id);
                $inviteEmail = $inviteUser?->email;
            }

            $useTraffic = $user['u'] + $user['d'];
            $remainingTraffic = Helper::trafficConvert($user['transfer_enable'] - $useTraffic);
            $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
            $resetDay = (new UserService())->getResetDay($user);

            $message = sprintf(
                "用户更新订阅成功\n" .
                "———————————————\n" .
                "用户邮箱：%s\n" .
                "用户地区：%s\n" .
                "IP地址：%s\n" .
                "User-Agent：%s\n" .
                "邀请人邮箱：%s\n" .
                "剩余流量：%s\n" .
                "套餐到期：%s\n" .
                "距离下次重置剩余：%s 天",
                $user->email,
                $region ?? '未知',
                $ip,
                $userAgent,
                $inviteEmail ?? '无',
                $remainingTraffic,
                $expiredDate,
                $resetDay ?? '无'
            );

            (new TelegramService())->sendMessageWithAdmin($message, (bool) $this->getConfig('notify_staff', false));
        } catch (\Exception $e) {
            \Log::error('[SubscribeNotify] 发送通知失败', ['error' => $e->getMessage()]);
        }
    }

    private function getRealIp($request): string
    {
        foreach (['CF-Connecting-IP', 'X-Forwarded-For'] as $header) {
            $value = $request->header($header);
            if ($value) {
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $request->ip();
    }

    private function getRegion(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        try {
            return (new \Ip2Region())->memorySearch($ip)['region'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }

}