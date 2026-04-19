<?php

namespace Plugin\RegisterNotify;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('user.register.after', [$this, 'handleRegister']);
    }

    public function handleRegister(User $user): void
    {
        try {
            $request = request();
            $ip = $this->getRealIp($request);
            $userAgent = $request->header('User-Agent', '');
            $region = $this->getRegion($ip);

            $inviteEmail = null;
            if ($this->getConfig('show_invite_email', true) && $user->invite_user_id) {
                $inviteUser = User::find($user->invite_user_id);
                $inviteEmail = $inviteUser?->email;
            }

            $message = sprintf(
                "新用户注册成功\n" .
                "———————————————\n" .
                "用户邮箱：%s\n" .
                "用户地区：%s\n" .
                "IP地址：%s\n" .
                "User-Agent：%s\n" .
                "邀请人邮箱：%s",
                $user->email,
                $region ?? '未知',
                $ip,
                $userAgent,
                $inviteEmail ?? '无'
            );

            (new TelegramService())->sendMessageWithAdmin($message, (bool) $this->getConfig('notify_staff', false));
        } catch (\Exception $e) {
            \Log::error('[RegisterNotify] 发送通知失败', ['error' => $e->getMessage()]);
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
