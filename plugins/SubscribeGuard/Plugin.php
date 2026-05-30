<?php

namespace Plugin\SubscribeGuard;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('user.subscribe.response', [$this, 'filterSubscribeResponse']);
    }

    public function filterSubscribeResponse($user)
    {
        if (!$this->getConfig('enabled', true)) {
            return $user;
        }

        if (!$this->shouldHideSubscribe($user)) {
            $user['can_copy_subscribe'] = true;
            $user['subscribe_unavailable_reason'] = null;
            return $user;
        }

        $user['subscribe_url'] = null;
        $user['token'] = null;
        $user['can_copy_subscribe'] = false;
        $user['subscribe_unavailable_reason'] = $this->getUnavailableReason($user);

        return $user;
    }

    private function shouldHideSubscribe($user): bool
    {
        if ($this->getConfig('hide_when_no_plan', true) && !$user->plan_id) {
            return true;
        }

        if (
            $this->getConfig('hide_when_expired', true)
            && $user->expired_at !== null
            && (int) $user->expired_at <= time()
        ) {
            return true;
        }

        return false;
    }

    private function getUnavailableReason($user): ?string
    {
        if (!$user->plan_id) {
            return 'no_plan';
        }

        if ($user->expired_at !== null && (int) $user->expired_at <= time()) {
            return 'expired';
        }

        return null;
    }
}
