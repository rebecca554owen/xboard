<?php

namespace Plugin\MailRateLimiter;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    protected $redisPrefix = 'mail_rate_limiter:';

    protected $timeWindows = [
        'second' => 1,
        'minute' => 60,
        'hour' => 3600,
        'daily' => 86400
    ];

    public function boot(): void
    {
        $this->filter('mail.send.before', function ($params) {
            return $this->checkRateLimit($params);
        });
    }

    protected function checkRateLimit($params)
    {
        if (!$this->getConfig('enable_rate_limit', true)) {
            return $params;
        }

        if (!$this->getConfig('limit_verify_codes', false) &&
            $this->isVerificationEmail($params)) {
            return $params;
        }

        $limits = $this->getLimits();
        $email = $params['email'] ?? 'unknown';

        if (!$this->atomicSecondLimitCheck($email, $limits['second'])) {
            return $this->retryWithSmartBackoff($params);
        }

        $result = $this->atomicLevelCheckAndRecord($params, $limits);
        if (!$result['success']) {
            return $this->retryWithSmartBackoff($params);
        }

        return $params;
    }

    protected function getLimits(): array
    {
        return [
            'second' => $this->getConfig('second_limit', 1),
            'minute' => $this->getConfig('minute_limit', 30),
            'hour' => $this->getConfig('hour_limit', 1500),
            'daily' => $this->getConfig('daily_limit', 30000)
        ];
    }

    /** 原子性的秒级别限制检查 */
    protected function atomicSecondLimitCheck($email, $secondLimit)
    {
        if ($secondLimit <= 0) {
            return true;
        }

        $redis = Redis::connection();
        $currentTime = microtime(true);
        $windowStart = floor($currentTime);

        $secondKey = $this->redisPrefix . 'second_atomic:' . $email;

        $luaScript = "
            local currentTime = tonumber(ARGV[2])
            local windowStart = math.floor(currentTime)

            -- 检查存储的时间窗口是否与当前时间窗口一致
            local storedWindow = redis.call('HGET', KEYS[1], 'window')

            if not storedWindow or tonumber(storedWindow) < windowStart then
                -- 新的时间窗口，重置计数
                redis.call('HSET', KEYS[1], 'window', windowStart)
                redis.call('HSET', KEYS[1], 'count', 1)
                redis.call('EXPIRE', KEYS[1], 2)
                return 1
            end

            local count = tonumber(redis.call('HGET', KEYS[1], 'count') or 0)

            if count >= tonumber(ARGV[1]) then
                return 0  -- 超过限制
            end

            -- 增加计数
            redis.call('HINCRBY', KEYS[1], 'count', 1)
            redis.call('EXPIRE', KEYS[1], 2)
            return 1
        ";

        $result = $redis->eval($luaScript, 1, $secondKey, $secondLimit, $currentTime);

        return $result == 1;
    }

    /** 原子性的级别限制检查和记录（分钟/小时/日） */
    protected function atomicLevelCheckAndRecord($params, $limits)
    {
        $currentTime = microtime(true);
        $email = $params['email'] ?? 'unknown';
        $redis = Redis::connection();

        foreach (['minute', 'hour', 'daily'] as $level) {
            $limit = $limits[$level];
            if ($limit <= 0) {
                continue;
            }

            $windowSeconds = $this->timeWindows[$level];
            $redisKey = $this->getRedisKey($level);

            $windowStart = match($level) {
                'minute' => floor($currentTime / 60) * 60,
                'hour' => floor($currentTime / 3600) * 3600,
                'daily' => floor($currentTime / 86400) * 86400,
            };

            $luaScript = "
                redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, ARGV[1])
                local count = redis.call('ZCOUNT', KEYS[1], ARGV[1], ARGV[2])

                if count >= tonumber(ARGV[4]) then
                    return {0, count}
                end

                local uniqueId = ARGV[2] .. '_' .. ARGV[5]
                redis.call('ZADD', KEYS[1], ARGV[2], uniqueId)
                redis.call('EXPIRE', KEYS[1], ARGV[3])
                return {1, count + 1}
            ";

            $uniqueSuffix = $email . '_' . (string)$currentTime;
            $result = $redis->eval($luaScript, 1, $redisKey,
                $windowStart, $currentTime, $windowSeconds * 2, $limit, $uniqueSuffix);

            if ($result[0] == 0) {
                return [
                    'success' => false,
                    'level' => $level,
                    'current_count' => $result[1],
                    'limit' => $limit
                ];
            }
        }

        return ['success' => true];
    }

    protected function retryWithSmartBackoff($params)
    {
        $limits = $this->getLimits();
        $email = $params['email'] ?? 'unknown';

        foreach (['daily', 'hour', 'minute', 'second'] as $level) {
            $limit = $limits[$level] ?? 0;
            if ($limit <= 0) {
                continue;
            }

            $isLimited = false;
            $waitSeconds = 0;
            $currentCount = 0;

            if ($level === 'second') {
                if (!$this->atomicSecondLimitCheck($email, $limit)) {
                    $isLimited = true;
                    $currentTime = microtime(true);
                    $windowStart = floor($currentTime);
                    $nextWindowStart = $windowStart + 1;
                    $waitSeconds = max(1, $nextWindowStart - $currentTime);
                    $currentCount = '原子检查';
                }
            } else {
                $windowSeconds = $this->timeWindows[$level];
                $currentTime = microtime(true);
                $windowStart = floor($currentTime / $windowSeconds) * $windowSeconds;
                $redisKey = $this->getRedisKey($level);

                $luaScript = "
                    redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, ARGV[1])
                    return redis.call('ZCOUNT', KEYS[1], ARGV[1], ARGV[2])
                ";

                $currentCount = Redis::eval($luaScript, 1, $redisKey, $windowStart, $currentTime);

                if ($currentCount >= $limit) {
                    $isLimited = true;
                    $nextWindowStart = $windowStart + $windowSeconds;
                    $waitSeconds = max(1, $nextWindowStart - $currentTime);
                }
            }

            if ($isLimited) {
                $waitSecondsInt = (int)ceil($waitSeconds);
                $logKey = $this->redisPrefix . 'window_log:' . $level . ':' . $windowStart . ':' . $email;

                $luaScript = "
                    if not redis.call('GET', KEYS[1]) then
                        redis.call('SETEX', KEYS[1], ARGV[1], 1)
                        return 1
                    end
                    return 0
                ";

                if (Redis::eval($luaScript, 1, $logKey, $this->timeWindows[$level] * 2)) {
                    $this->log("{$level}级别限制: {$currentCount}/{$limit}，等待 {$waitSecondsInt}s");
                }

                sleep($waitSecondsInt);
                return $this->checkRateLimit($params);
            }
        }

        return $params;
    }

    protected function isVerificationEmail($params): bool
    {
        $templateName = $params['template_name'] ?? '';
        $subject = $params['subject'] ?? '';

        $patterns = ['verify', 'verification', 'code', '验证码'];

        foreach ($patterns as $pattern) {
            if (stripos($templateName, $pattern) !== false || stripos($subject, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function getRedisKey($level)
    {
        return $this->redisPrefix . $level . ':global';
    }

    protected function log($message)
    {
        if (!$this->getConfig('debug_logs', false)) {
            return;
        }

        $logMessage = "[MailRateLimiter] {$message}";
        Log::info($logMessage);
    }
}