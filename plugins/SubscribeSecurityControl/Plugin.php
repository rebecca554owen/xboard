<?php

namespace Plugin\SubscribeSecurityControl;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const CACHE_INDEX_KEY = 'subscribe_security:cache_index';
    private const LAST_CLEANUP_KEY = 'subscribe_security:last_cleanup_at';
    private const STARTUP_CLEANUP_LOCK_KEY = 'subscribe_security:startup_cleanup_lock';
    private const CACHE_PREFIXES = [
        'subscribe_security:user_daily:',
        'subscribe_security:ip_access:',
        'subscribe_security:alert_count:',
    ];

    public function boot(): void
    {
        $this->listen('client.subscribe.before', function () {
            $this->performSecurityCheck();
        });

        $this->filter('guest_comm_config', function ($config) {
            $config['subscribe_security_enable'] = $this->getConfig('enable', true);
            return $config;
        });

        if ($this->getConfig('cleanup_on_startup', false) && Cache::add(self::STARTUP_CLEANUP_LOCK_KEY, time(), 3600)) {
            $this->performCleanup();
        }
    }

    public function schedule(Schedule $schedule): void
    {
        if (!$this->getConfig('auto_cleanup_enable', true)) {
            return;
        }

        $schedule->call(function (): void {
            $intervalHours = max(1, (int) $this->getConfig('cache_cleanup_interval', 24));
            $lastCleanupAt = (int) Cache::get(self::LAST_CLEANUP_KEY, 0);

            if ($lastCleanupAt > 0 && (time() - $lastCleanupAt) < ($intervalHours * 3600)) {
                return;
            }

            $this->performCleanup();
            Cache::forever(self::LAST_CLEANUP_KEY, time());
        })->hourly()->onOneServer();
    }

    private function getConfigArray(string $key, array $default = []): array
    {
        $config = $this->getConfig($key, $default);

        if (is_array($config)) {
            return array_values(array_filter(array_map('trim', $config), fn ($value) => $value !== ''));
        }

        if (is_string($config)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $config)), fn ($value) => $value !== ''));
        }

        return $default;
    }

    private function performSecurityCheck(): void
    {
        if (!$this->getConfig('enable', true)) {
            return;
        }

        $request = request();
        $clientIp = $this->getClientIp($request);
        $userAgent = $request->header('User-Agent', '');

        if ($this->isIpWhitelisted($clientIp)) {
            $this->logSecurityEvent('ip_whitelisted', $clientIp, $userAgent);
            $this->recordAccess($clientIp, $userAgent);
            return;
        }

        if ($this->getConfig('whitelist_mode', true) && !$this->isUserAgentAllowed($userAgent)) {
            $this->logSecurityEvent('ua_blocked', $clientIp, $userAgent);
            $this->blockRequest('User-Agent不在白名单中');
            return;
        }

        $user = $this->currentRequestUser();
        if ($this->getConfig('user_daily_limit_enable', false) && $user && $this->isUserDailyLimitExceeded((int) $user->id)) {
            $this->logSecurityEvent('user_daily_limit_exceeded', $clientIp, $userAgent, (int) $user->id);
            $this->blockRequest('用户每日获取次数超限');
            return;
        }

        if ($this->getConfig('ip_limit_enable', false) && $this->isIpRateLimited($clientIp)) {
            $this->logSecurityEvent('ip_rate_limited', $clientIp, $userAgent);
            $this->blockRequest('IP访问频率超限');
            return;
        }

        $this->recordAccess($clientIp, $userAgent);
        $this->logSecurityEvent('access_allowed', $clientIp, $userAgent, $user ? (int) $user->id : null);
    }

    private function currentRequestUser(): mixed
    {
        return request()->user() ?: Auth::user();
    }

    private function getClientIp(Request $request): string
    {
        if (!$this->getConfig('trust_proxy_headers', false)) {
            return $request->ip();
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $ip = $request->server($header);
            if (!empty($ip) && strtolower($ip) !== 'unknown') {
                $ip = trim(explode(',', $ip)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $request->ip();
    }

    private function isIpWhitelisted(string $ip): bool
    {
        foreach ($this->getConfigArray('ip_whitelist', []) as $whitelistEntry) {
            if ($this->ipInRange($ip, $whitelistEntry)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!str_contains($range, '/')) {
            return filter_var($range, FILTER_VALIDATE_IP) && inet_pton($ip) === inet_pton($range);
        }

        [$subnet, $mask] = array_pad(explode('/', $range, 2), 2, null);
        if ($subnet === null || $mask === null || !ctype_digit((string) $mask)) {
            return false;
        }

        $mask = (int) $mask;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask < 0 || $mask > 32) {
                return false;
            }

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = $mask === 0 ? 0 : (-1 << (32 - $mask));
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || $mask < 0 || $mask > 128) {
                return false;
            }

            return $this->ipv6InCidr($ip, $subnet, $mask);
        }

        return false;
    }

    private function ipv6InCidr(string $ip, string $subnet, int $mask): bool
    {
        $ipBytes = inet_pton($ip);
        $subnetBytes = inet_pton($subnet);
        if ($ipBytes === false || $subnetBytes === false) {
            return false;
        }

        $fullBytes = intdiv($mask, 8);
        $remainingBits = $mask % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($subnetBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $byteMask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipBytes[$fullBytes]) & $byteMask) === (ord($subnetBytes[$fullBytes]) & $byteMask);
    }

    private function isUserAgentAllowed(string $userAgent): bool
    {
        if ($userAgent === '') {
            return $this->getConfig('allow_empty_ua', false);
        }

        $strictMode = $this->getConfig('strict_mode', false);
        $userAgentLower = strtolower($userAgent);

        foreach ($this->getConfigArray('ua_whitelist', []) as $allowedUA) {
            if ($strictMode) {
                if (strtolower($allowedUA) === $userAgentLower) {
                    return true;
                }
            } elseif (stripos($userAgent, $allowedUA) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isUserDailyLimitExceeded(int $userId): bool
    {
        $limitCount = (int) $this->getConfig('user_daily_limit_count', 1000);
        return (int) Cache::get($this->userDailyCacheKey($userId), 0) >= $limitCount;
    }

    private function isIpRateLimited(string $ip): bool
    {
        $limitCount = (int) $this->getConfig('ip_limit_count', 100);
        return (int) Cache::get($this->ipAccessCacheKey($ip), 0) >= $limitCount;
    }

    private function recordAccess(string $ip, string $userAgent): void
    {
        $limitWindow = max(60, (int) $this->getConfig('ip_limit_window', 3600));
        $ipCacheKey = $this->ipAccessCacheKey($ip);
        Cache::put($ipCacheKey, ((int) Cache::get($ipCacheKey, 0)) + 1, $limitWindow);
        $this->rememberCacheKey($ipCacheKey);

        $user = $this->currentRequestUser();
        if ($user) {
            $userCacheKey = $this->userDailyCacheKey((int) $user->id);
            Cache::put($userCacheKey, ((int) Cache::get($userCacheKey, 0)) + 1, 86400);
            $this->rememberCacheKey($userCacheKey);
        }
    }

    private function logSecurityEvent(string $type, string $ip, string $userAgent, ?int $userId = null): void
    {
        $logData = [
            'type' => $type,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'user_id' => $userId,
            'timestamp' => now()->toISOString(),
            'request_uri' => request()->getRequestUri(),
        ];

        if ($this->getConfig('log_enable', true)) {
            $logLevel = in_array($type, ['access_allowed', 'ip_whitelisted'], true) ? 'info' : 'warning';
            Log::channel('daily')->{$logLevel}('订阅安全控制', $logData);
        }

        if (!in_array($type, ['access_allowed', 'ip_whitelisted'], true)) {
            $this->checkAndSendAlert($type);
        }
    }

    private function checkAndSendAlert(string $type): void
    {
        if (!$this->getConfig('alert_enable', false)) {
            return;
        }

        $threshold = (int) $this->getConfig('alert_threshold', 100);
        $cacheKey = 'subscribe_security:alert_count:' . date('YmdH');
        $alertCount = (int) Cache::get($cacheKey, 0) + 1;
        Cache::put($cacheKey, $alertCount, 3600);
        $this->rememberCacheKey($cacheKey);

        if ($alertCount >= $threshold) {
            Log::channel('daily')->critical('订阅安全控制告警', [
                'message' => "检测到大量恶意访问，1小时内已拦截 {$alertCount} 次",
                'type' => $type,
                'threshold' => $threshold,
                'hour' => date('Y-m-d H:00:00'),
            ]);
        }
    }

    private function blockRequest(string $reason): void
    {
        $responseType = $this->getConfig('block_response_type', '404');

        match ($responseType) {
            '403' => $this->intercept(response('Forbidden', 403, ['Content-Type' => 'text/plain'])),
            'empty' => $this->intercept(response('', 200, ['Content-Type' => 'text/plain'])),
            'fake' => $this->intercept(response($this->getConfig('fake_subscription', '# 无效的订阅链接'), 200, ['Content-Type' => 'text/plain'])),
            default => $this->intercept(response('Not Found', 404, ['Content-Type' => 'text/plain'])),
        };
    }

    public function performCleanup(): array
    {
        $results = [
            'cache_cleared' => 0,
            'logs_cleared' => 0,
            'errors' => [],
        ];

        try {
            $results['cache_cleared'] = $this->clearCacheRecords();
            Log::channel('daily')->info('订阅安全控制清理完成', [
                'cache_index_pruned' => $results['cache_cleared'],
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            Log::channel('daily')->error('订阅安全控制清理失败', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ]);
        }

        return $results;
    }

    public function clearAllRecords(): array
    {
        return $this->performCleanup();
    }

    public function clearUserRecords(int $userId): bool
    {
        return $this->forgetIndexedKey($this->userDailyCacheKey($userId));
    }

    public function clearIpRecords(string $ip): bool
    {
        return $this->forgetIndexedKey($this->ipAccessCacheKey($ip));
    }

    public function getCleanupStats(): array
    {
        $keys = $this->indexedCacheKeys();

        return [
            'cache_keys_count' => count($keys),
            'log_files_count' => 0,
            'total_cache_size' => 0,
            'total_log_size' => 0,
            'keys' => $keys,
        ];
    }

    private function clearCacheRecords(): int
    {
        $pruned = 0;
        $activeKeys = [];

        foreach ($this->indexedCacheKeys() as $key) {
            if (Cache::has($key)) {
                $activeKeys[] = $key;
            } else {
                $pruned++;
            }
        }

        Cache::forever(self::CACHE_INDEX_KEY, $activeKeys);
        return $pruned;
    }

    private function rememberCacheKey(string $key): void
    {
        if (!$this->isManagedCacheKey($key)) {
            return;
        }

        $keys = $this->indexedCacheKeys();
        $keys[] = $key;
        Cache::forever(self::CACHE_INDEX_KEY, array_values(array_unique($keys)));
    }

    private function forgetIndexedKey(string $key): bool
    {
        $forgotten = Cache::forget($key);
        $keys = array_values(array_filter($this->indexedCacheKeys(), fn ($indexedKey) => $indexedKey !== $key));
        Cache::forever(self::CACHE_INDEX_KEY, $keys);
        return $forgotten;
    }

    private function indexedCacheKeys(): array
    {
        $keys = Cache::get(self::CACHE_INDEX_KEY, []);
        if (!is_array($keys)) {
            return [];
        }

        return array_values(array_filter(array_unique($keys), fn ($key) => is_string($key) && $this->isManagedCacheKey($key)));
    }

    private function isManagedCacheKey(string $key): bool
    {
        foreach (self::CACHE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function ipAccessCacheKey(string $ip): string
    {
        return "subscribe_security:ip_access:{$ip}";
    }

    private function userDailyCacheKey(int $userId): string
    {
        return "subscribe_security:user_daily:{$userId}:" . date('Y-m-d');
    }
}
