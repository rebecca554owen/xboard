<?php

namespace Plugin\BadUAFilter;

use App\Services\Plugin\AbstractPlugin;
use App\Utils\Helper;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('client.subscribe.before', [$this, 'checkUA']);
    }

    public function checkUA(): void
    {
        $request = request();
        $user = $request->user();

        if (!$user) {
            return;
        }

        $userAgent = strtolower($request->header('User-Agent', ''));
        $rawKeywords = $this->getConfig('suspicious_uas', '');

        $keywords = array_filter(
            array_map('trim', explode(',', $rawKeywords))
        );

        foreach ($keywords as $keyword) {
            if (stripos($userAgent, strtolower($keyword)) !== false) {
                $user->uuid = Helper::guid(true);
                $user->token = Helper::guid();
                $user->save();

                $redirectUrl = $this->getConfig('redirect_url', 'https://www.bing.com');
                $this->intercept(redirect($redirectUrl));
            }
        }
    }
}
