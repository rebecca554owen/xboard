<?php

namespace Plugin\SubscriptionStatistics;

use App\Models\StatUser;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class Plugin extends AbstractPlugin
{
    private const DEFAULT_PREFIX = 'subscription_statistics';
    private const TEMP_KEY_TTL = 60;

    private TelegramService $telegramService;

    public function boot(): void
    {
        $this->telegramService = new TelegramService();

        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        $this->listen('client.subscribe.before', function () {
            $this->recordSubscriptionAccess();
        });

        $this->filter('telegram.bot.commands', function ($commands) {
            $commands[] = [
                'command' => '/sub',
                'description' => '订阅统计查询'
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

            $parsed = $this->parseSubCommand($msg->text);
            if (!$parsed) {
                return false;
            }

            [$type, $days, $limit] = $parsed;
            $this->handleSubCommand($msg, $type, $days, $limit);
            return true;
        });
    }

    private function recordSubscriptionAccess(): void
    {
        if (!$this->getConfig('enabled', true)) {
            return;
        }

        $request = request();
        $user = $this->getUserFromRequest($request);
        $statData = $this->buildStatData($request, $user);
        $this->storeStatData($statData);
    }

    private function getUserFromRequest(Request $request): ?User
    {
        $user = $request->user();

        if (!$user) {
            $token = $request->input('token', $request->route('token'));
            $user = $token ? $this->findUserByToken($token) : null;
        }

        return $user;
    }

    private function buildStatData(Request $request, ?User $user): array
    {
        $ip = null;
        if ($this->getConfig('track_ip', true)) {
            $ip = $this->normalizeIp($this->getRealIpAddress($request));
        }

        $ua = $this->getConfig('track_ua', true)
            ? $this->parseUserAgent($request->header('User-Agent'))
            : '无UA';

        return [
            'user_email' => $user?->email,
            'user_rank_member' => $user?->email ?: '未知用户',
            'ip' => $ip,
            'ip_rank_member' => $ip ?: '无IP',
            'ua' => $ua,
        ];
    }

    private function storeStatData(array $statData): void
    {
        $dayKey = Carbon::now(config('app.timezone'))->format('Ymd');
        $keys = $this->getDayMetricKeys($dayKey);
        $retentionSeconds = $this->getRetentionSeconds();
        $ipUserKey = $this->buildMemberSetKey($dayKey, 'ip_users', $statData['ip_rank_member']);
        $ipUaKey = $this->buildMemberSetKey($dayKey, 'ip_uas', $statData['ip_rank_member']);
        $uaUserKey = $this->buildMemberSetKey($dayKey, 'ua_users', $statData['ua']);

        $expireKeys = [
            $keys['total'],
            $keys['user_rank'],
            $keys['ua_rank'],
            $keys['ip_rank'],
            $keys['users'],
            $keys['ips'],
            $keys['uas'],
            $ipUserKey,
            $ipUaKey,
            $uaUserKey,
        ];

        $redis = Redis::connection();
        $redis->pipeline(function ($pipe) use ($keys, $statData, $retentionSeconds, $ipUserKey, $ipUaKey, $uaUserKey, $expireKeys) {
            $pipe->incr($keys['total']);
            $pipe->zincrby($keys['user_rank'], 1, $statData['user_rank_member']);
            $pipe->zincrby($keys['ua_rank'], 1, $statData['ua']);
            $pipe->zincrby($keys['ip_rank'], 1, $statData['ip_rank_member']);
            $pipe->sadd($keys['uas'], $statData['ua']);
            $pipe->sadd($ipUaKey, $statData['ua']);

            if ($statData['user_email']) {
                $pipe->sadd($keys['users'], $statData['user_email']);
                $pipe->sadd($ipUserKey, $statData['user_email']);
                $pipe->sadd($uaUserKey, $statData['user_email']);
            }

            if ($statData['ip']) {
                $pipe->sadd($keys['ips'], $statData['ip']);
            }

            foreach (array_unique($expireKeys) as $key) {
                $pipe->expire($key, $retentionSeconds);
            }
        });
    }

    private function findUserByToken($token): ?User
    {
        if (!$token) {
            return null;
        }

        return User::where('token', $token)->first();
    }

    private function parseSubCommand(string $text): ?array
    {
        if (!preg_match('/^\/sub(\s+(user|ua|ip)(?:\s+(\d+)(?:\s+(\d+))?)?)?(\s+(\d+))?$/', $text, $matches)) {
            return null;
        }

        $type = $matches[2] ?? null;

        if ($type) {
            return $this->parseTypedCommand($matches);
        }

        return $this->parseSummaryCommand($matches);
    }

    private function parseTypedCommand(array $matches): array
    {
        $type = $matches[2];
        $days = 0;
        $limit = 20;

        if (isset($matches[3]) && isset($matches[4])) {
            $days = intval($matches[3]);
            $limit = intval($matches[4]);
        } elseif (isset($matches[3])) {
            $num = intval($matches[3]);
            if ($num <= 30) {
                $days = $num;
            } else {
                $limit = $num;
            }
        }

        return [$type, $days, $this->validateLimit($limit)];
    }

    private function parseSummaryCommand(array $matches): array
    {
        return [null, isset($matches[6]) ? intval($matches[6]) : 0, null];
    }

    private function validateLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }

    private function handleSubCommand($message, ?string $type = null, int $days = 0, ?int $limit = null): void
    {
        if (!$this->validateCommandAccess($message)) {
            return;
        }

        try {
            $days = max(0, min($days, 30));
            $result = $this->generateReport($type, $days, $limit);
            $this->sendReport($message, $result, $days);
        } catch (\Throwable $e) {
            $this->handleCommandError($message, $e);
        }
    }

    private function validateCommandAccess($message): bool
    {
        if (!$message->is_private) {
            return false;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        return $user && ($user->is_admin || $user->is_staff);
    }

    private function generateReport(?string $type, int $days, ?int $limit = null): array
    {
        return match ($type) {
            'user' => $this->generateUserRankingReport($days, $limit),
            'ua' => $this->generateUaRankingReport($days, $limit),
            'ip' => $this->generateIpRankingReport($days, $limit),
            default => $this->generateSummaryReport($days),
        };
    }

    private function sendReport($message, array $result, int $days): void
    {
        if ($result['has_data']) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                implode("\n", $result['report']),
                'markdown'
            );
            return;
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $this->telegramService->sendMessage(
            $message->chat_id,
            "📊 {$periodLabel}暂无订阅访问数据",
            'markdown'
        );
    }

    private function handleCommandError($message, \Throwable $e): void
    {
        \Log::error('SubscriptionStatistics command failed', [
            'error' => $e->getMessage(),
            'chat_id' => $message->chat_id,
            'command' => $message->text,
            'trace' => $e->getTraceAsString()
        ]);

        $errorMessage = '❌ 命令执行失败';
        if (app()->environment('local', 'testing')) {
            $errorMessage .= '：' . $e->getMessage();
        }

        $this->telegramService->sendMessage($message->chat_id, $errorMessage);
    }

    private function generateSummaryReport(int $days = 0): array
    {
        $aggregated = $this->aggregateSubscriptionData($days, 5, 5, 5);
        if ($aggregated['totalAccess'] === 0) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $report = $this->buildSummaryReport(
            $periodLabel,
            $aggregated['stats'],
            $aggregated['uaRanking'],
            $aggregated['userRanking'],
            $aggregated['ipRanking']
        );

        return ['has_data' => true, 'report' => $report];
    }

    private function buildSummaryReport(string $periodLabel, $stats, $uaRanking, $userRanking, $ipRanking): array
    {
        $report = [
            '📊 订阅访问统计分析',
            "时段：{$periodLabel}",
            "📈 总访问{$stats['totalAccess']}次 | {$stats['uniqueUsers']}用户 | 用户平均IP{$stats['avgIPPerUser']} | 用户平均UA{$stats['avgUAPerUser']}",
            '══════════════════════════',
            '',
            '👥 用户排行 TOP 5：',
            '══════════════════════════',
            '💡 使用 `/sub user` 查看更多'
        ];

        foreach ($userRanking as $index => $user) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($user['count']);
            $report[] = "{$rank}. `{$user['email']}`：{$user['count']} 次 {$frequencyIcon}";
        }

        $report[] = '';
        $report[] = '🌐 IP访问排行 TOP 5：';
        $report[] = '══════════════════════════';
        $report[] = '💡 使用 `/sub ip` 查看更多';

        foreach ($ipRanking as $index => $ip) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($ip['count']);
            $report[] = "{$rank}. `{$ip['ip']}`：{$ip['count']} 次 {$frequencyIcon}";
            $report[] = "    └ {$ip['unique_users']} 用户 | {$ip['unique_uas']} 种客户端";
        }

        $report[] = '';
        $report[] = '📱 UA排行 TOP 5：';
        $report[] = '══════════════════════════';
        $report[] = '💡 使用 `/sub ua` 查看更多';

        foreach ($uaRanking as $index => $ua) {
            $rank = $index + 1;
            $report[] = "{$rank}. `{$ua['ua']}`：{$ua['count']} 次 ({$ua['users']} 用户)";
        }

        return $report;
    }

    private function generateUserRankingReport(int $days = 0, ?int $limit = 20): array
    {
        $limit = $this->validateLimit($limit ?? 20);
        $aggregated = $this->aggregateSubscriptionData($days, $limit, 0, 0);
        if ($aggregated['totalAccess'] === 0) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $report = [
            "👥 用户排行 TOP {$limit} 💡 使用 `/sub user {$limit}` 查看更多",
            "时段：{$periodLabel}",
            '══════════════════════════'
        ];

        foreach ($aggregated['userRanking'] as $index => $user) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($user['count']);
            $report[] = "{$rank}. `{$user['email']}`：{$user['count']} 次 {$frequencyIcon}";
            $report[] = "    └ 时段流量 {$user['period_traffic_formatted']} | 当前已用 {$user['used_traffic_formatted']} | 总流量 {$user['total_traffic_formatted']}";
        }

        return ['has_data' => true, 'report' => $report];
    }

    private function generateUaRankingReport(int $days = 0, ?int $limit = 20): array
    {
        $limit = $this->validateLimit($limit ?? 20);
        $aggregated = $this->aggregateSubscriptionData($days, 0, $limit, 0);
        if ($aggregated['totalAccess'] === 0) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $report = [
            "📱 UA排行 TOP {$limit} 💡 使用 `/sub ua {$limit}` 查看更多",
            "时段：{$periodLabel}",
            '══════════════════════════'
        ];

        foreach ($aggregated['uaRanking'] as $index => $ua) {
            $rank = $index + 1;
            $report[] = "{$rank}. `{$ua['ua']}`：{$ua['count']} 次 ({$ua['users']} 用户)";
        }

        return ['has_data' => true, 'report' => $report];
    }

    private function generateIpRankingReport(int $days = 0, ?int $limit = 20): array
    {
        $limit = $this->validateLimit($limit ?? 20);
        $aggregated = $this->aggregateSubscriptionData($days, 0, 0, $limit);
        if ($aggregated['totalAccess'] === 0) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $report = [
            "🌐 IP访问排行 TOP {$limit} 💡 使用 `/sub ip {$limit}` 查看更多",
            "时段：{$periodLabel}",
            '══════════════════════════'
        ];

        foreach ($aggregated['ipRanking'] as $index => $ip) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($ip['count']);
            $report[] = "{$rank}. `{$ip['ip']}`：{$ip['count']} 次 {$frequencyIcon}";
            $report[] = "    └ {$ip['unique_users']} 用户 | {$ip['unique_uas']} 种客户端";
        }

        return ['has_data' => true, 'report' => $report];
    }

    private function aggregateSubscriptionData(int $days, int $userLimit, int $uaLimit, int $ipLimit): array
    {
        $dayKeys = $this->getQueryDayKeys($days);
        $metricKeys = array_map(fn ($dayKey) => $this->getDayMetricKeys($dayKey), $dayKeys);
        $totalAccess = $this->sumStringValues(array_column($metricKeys, 'total'));

        if ($totalAccess === 0) {
            return [
                'totalAccess' => 0,
                'stats' => [
                    'totalAccess' => 0,
                    'uniqueUsers' => 0,
                    'avgIPPerUser' => 0,
                    'avgUAPerUser' => 0,
                ],
                'userRanking' => collect(),
                'uaRanking' => collect(),
                'ipRanking' => collect(),
            ];
        }

        $uniqueUserCount = $this->getUnionSetCount(array_column($metricKeys, 'users'));
        $uniqueIPCount = $this->getUnionSetCount(array_column($metricKeys, 'ips'));
        $uniqueUACount = $this->getUnionSetCount(array_column($metricKeys, 'uas'));

        return [
            'totalAccess' => $totalAccess,
            'stats' => [
                'totalAccess' => $totalAccess,
                'uniqueUsers' => $uniqueUserCount,
                'avgIPPerUser' => round($uniqueIPCount / max($uniqueUserCount, 1), 1),
                'avgUAPerUser' => round($uniqueUACount / max($uniqueUserCount, 1), 1),
            ],
            'userRanking' => $this->getUserRanking($dayKeys, $userLimit, $days),
            'uaRanking' => $this->getUaRanking($dayKeys, $uaLimit),
            'ipRanking' => $this->getIpRanking($dayKeys, $ipLimit),
        ];
    }

    private function getUserRanking(array $dayKeys, int $limit, int $days)
    {
        if ($limit <= 0) {
            return collect();
        }

        $entries = $this->getMergedSortedSetEntries($dayKeys, 'user_rank', $limit);
        $ranking = [];

        foreach ($entries as $email => $count) {
            if (!is_scalar($email)) {
                continue;
            }

            $ranking[] = [
                'email' => (string) $email,
                'count' => (int) $count,
            ];
        }

        return $this->attachUserTrafficData(collect($ranking), $days);
    }

    private function attachUserTrafficData(Collection $ranking, int $days): Collection
    {
        if ($ranking->isEmpty()) {
            return $ranking;
        }

        $users = $this->getUsersByEmail(
            $ranking->pluck('email')
                ->filter(fn ($email) => $email !== '未知用户')
                ->unique()
                ->values()
                ->all()
        );

        $periodTrafficByEmail = $this->getPeriodTrafficByEmail($users, $days);

        return $ranking->map(function (array $item) use ($users, $periodTrafficByEmail) {
            $user = $users->get($item['email']);
            $usedTraffic = (int) (($user->u ?? 0) + ($user->d ?? 0));
            $totalTraffic = (int) ($user->transfer_enable ?? 0);
            $periodTraffic = (int) ($periodTrafficByEmail[$item['email']] ?? 0);

            return array_merge($item, [
                'period_traffic' => $periodTraffic,
                'used_traffic' => $usedTraffic,
                'total_traffic' => $totalTraffic,
                'period_traffic_formatted' => Helper::trafficConvert($periodTraffic),
                'used_traffic_formatted' => Helper::trafficConvert($usedTraffic),
                'total_traffic_formatted' => Helper::trafficConvert($totalTraffic),
            ]);
        });
    }

    private function getUsersByEmail(array $emails): Collection
    {
        if (empty($emails)) {
            return collect();
        }

        return User::query()
            ->select(['id', 'email', 'u', 'd', 'transfer_enable'])
            ->whereIn('email', $emails)
            ->get()
            ->keyBy('email');
    }

    private function getPeriodTrafficByEmail(Collection $users, int $days): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $trafficByUserId = [];
        try {
            $timeRange = $this->getStatTimeRange($days);
            $trafficRows = StatUser::query()
                ->select('user_id', DB::raw('SUM(u + d) as total_traffic'))
                ->whereIn('user_id', $users->pluck('id')->all())
                ->where('record_at', '>=', $timeRange['startAt'])
                ->where('record_at', '<', $timeRange['endAt'])
                ->groupBy('user_id')
                ->get();

            foreach ($trafficRows as $row) {
                $trafficByUserId[(int) $row->user_id] = (int) $row->total_traffic;
            }
        } catch (\Throwable $e) {
            \Log::warning('SubscriptionStatistics period traffic fallback to zero', [
                'error' => $e->getMessage(),
                'days' => $days,
            ]);
        }

        $trafficByEmail = [];
        foreach ($users as $email => $user) {
            $trafficByEmail[(string) $email] = $trafficByUserId[(int) $user->id] ?? 0;
        }

        return $trafficByEmail;
    }

    private function getUaRanking(array $dayKeys, int $limit)
    {
        if ($limit <= 0) {
            return collect();
        }

        $entries = $this->getMergedSortedSetEntries($dayKeys, 'ua_rank', $limit);
        $ranking = [];

        foreach ($entries as $ua => $count) {
            if (!is_scalar($ua)) {
                continue;
            }

            $ranking[] = [
                'ua' => (string) $ua,
                'count' => (int) $count,
                'users' => $this->getUnionSetCount($this->buildMemberSetKeys($dayKeys, 'ua_users', (string) $ua)),
            ];
        }

        return collect($ranking);
    }

    private function getIpRanking(array $dayKeys, int $limit)
    {
        if ($limit <= 0) {
            return collect();
        }

        $entries = $this->getMergedSortedSetEntries($dayKeys, 'ip_rank', $limit);
        $ranking = [];

        foreach ($entries as $ip => $count) {
            if (!is_scalar($ip)) {
                continue;
            }

            $ranking[] = [
                'ip' => (string) $ip,
                'count' => (int) $count,
                'unique_users' => $this->getUnionSetCount($this->buildMemberSetKeys($dayKeys, 'ip_users', (string) $ip)),
                'unique_uas' => $this->getUnionSetCount($this->buildMemberSetKeys($dayKeys, 'ip_uas', (string) $ip)),
            ];
        }

        return collect($ranking);
    }

    private function getMergedSortedSetEntries(array $dayKeys, string $metric, int $limit): array
    {
        $redis = Redis::connection();
        $sourceKeys = [];
        foreach ($dayKeys as $dayKey) {
            $sourceKeys[] = $this->getDayMetricKeys($dayKey)[$metric];
        }

        if (count($sourceKeys) === 1) {
            $entries = $redis->zrevrange($sourceKeys[0], 0, $limit - 1, true);
            return $this->normalizeSortedSetEntries($entries);
        }

        $merged = [];
        foreach ($sourceKeys as $sourceKey) {
            $entries = $this->normalizeSortedSetEntries($redis->zrevrange($sourceKey, 0, -1, true));
            foreach ($entries as $member => $score) {
                $merged[$member] = ($merged[$member] ?? 0) + $score;
            }
        }

        arsort($merged, SORT_NUMERIC);

        return array_slice($merged, 0, $limit, true);
    }

    private function normalizeSortedSetEntries($entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $normalized = [];
        foreach ($entries as $member => $score) {
            if (is_array($score)) {
                $itemMember = $score[0] ?? $score['member'] ?? null;
                $itemScore = $score[1] ?? $score['score'] ?? 0;
            } else {
                $itemMember = $member;
                $itemScore = $score;
            }

            if (!is_scalar($itemMember)) {
                continue;
            }

            $normalized[(string) $itemMember] = (float) $itemScore;
        }

        return $normalized;
    }

    private function sumStringValues(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        $values = Redis::connection()->mget($keys);
        if (!is_array($values)) {
            return 0;
        }

        return array_sum(array_map('intval', $values));
    }

    private function getUnionSetCount(array $keys): int
    {
        $keys = array_values(array_filter(array_unique($keys)));
        if (empty($keys)) {
            return 0;
        }

        $redis = Redis::connection();

        if (count($keys) === 1) {
            return (int) $redis->scard($keys[0]);
        }

        $members = [];
        foreach ($keys as $key) {
            $setMembers = $redis->smembers($key);
            if (!is_array($setMembers)) {
                continue;
            }

            foreach ($setMembers as $member) {
                if (!is_scalar($member)) {
                    continue;
                }

                $members[(string) $member] = true;
            }
        }

        return count($members);
    }

    private function buildMemberSetKeys(array $dayKeys, string $metric, string $member): array
    {
        $keys = [];
        foreach ($dayKeys as $dayKey) {
            $keys[] = $this->buildMemberSetKey($dayKey, $metric, $member);
        }

        return $keys;
    }

    private function getDayMetricKeys(string $dayKey): array
    {
        $prefix = $this->getRedisPrefix() . ':' . $dayKey;

        return [
            'total' => $prefix . ':total',
            'user_rank' => $prefix . ':user_rank',
            'ua_rank' => $prefix . ':ua_rank',
            'ip_rank' => $prefix . ':ip_rank',
            'users' => $prefix . ':users',
            'ips' => $prefix . ':ips',
            'uas' => $prefix . ':uas',
        ];
    }

    private function buildMemberSetKey(string $dayKey, string $metric, string $member): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            $this->getRedisPrefix(),
            $dayKey,
            $metric,
            sha1($member)
        );
    }

    private function buildTempKey(string $metric): string
    {
        return sprintf(
            '%s:tmp:%s:%s',
            $this->getRedisPrefix(),
            $metric,
            str_replace('.', '', uniqid('', true))
        );
    }

    private function getRedisPrefix(): string
    {
        return (string) $this->getConfig('redis_prefix', self::DEFAULT_PREFIX);
    }

    private function getRetentionSeconds(): int
    {
        $retentionDays = max(31, (int) $this->getConfig('retention_days', 35));
        return $retentionDays * 86400;
    }

    private function getQueryDayKeys(int $days): array
    {
        $timezone = config('app.timezone');
        $today = Carbon::today($timezone);

        if ($days === 0) {
            return [$today->format('Ymd')];
        }

        if ($days === 1) {
            return [$today->copy()->subDay()->format('Ymd')];
        }

        $cursor = $today->copy()->subDays($days);
        $keys = [];

        while ($cursor->lte($today)) {
            $keys[] = $cursor->format('Ymd');
            $cursor->addDay();
        }

        return $keys;
    }

    private function formatPeriodLabel(int $days): string
    {
        $timeRange = $this->getTimeRange($days);
        $start = date('Y-m-d H:i', $timeRange['startAt']);
        $end = date('Y-m-d H:i', $timeRange['endAt']);
        return "{$start} ~ {$end}";
    }

    private function getFrequencyIcon(int $count): string
    {
        return match (true) {
            $count >= 100 => '🔥',
            $count >= 50 => '⚡',
            $count >= 20 => '📈',
            $count >= 10 => '📊',
            default => '📉'
        };
    }

    private function parseUserAgent($userAgent): string
    {
        if (empty($userAgent)) {
            return '无UA';
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9\-_]*)/', $userAgent, $matches)) {
            return substr($matches[1], 0, 30);
        }

        return '解析失败';
    }

    private function normalizeIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);
        return $ip === '' ? null : $ip;
    }

    private function getTimeRange(int $days = 0): array
    {
        return match ($days) {
            0 => [
                'startAt' => strtotime('today'),
                'endAt' => strtotime('tomorrow')
            ],
            1 => [
                'startAt' => strtotime('-1 day', strtotime('today')),
                'endAt' => strtotime('today')
            ],
            default => [
                'startAt' => strtotime("-{$days} days", strtotime('today')),
                'endAt' => time()
            ]
        };
    }

    private function getStatTimeRange(int $days): array
    {
        $timezone = config('app.timezone');
        $timeRange = $this->getTimeRange($days);

        return [
            'startAt' => $timeRange['startAt'],
            'endAt' => max($timeRange['startAt'] + 1, $timeRange['endAt']),
        ];
    }

    private function getRealIpAddress(Request $request): string
    {
        $headers = [
            'CF-Connecting-IP',
            'True-Client-IP',
            'X-Real-IP',
            'X-Forwarded-For',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'X-Cluster-Client-IP',
            'X-Original-Forwarded-For',
            'HTTP_CLIENT_IP',
            'WL-Proxy-Client-IP',
        ];

        foreach ($headers as $header) {
            $ip = $request->header($header);
            if (!$ip) {
                continue;
            }

            if (strtolower($header) === 'x-forwarded-for') {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }

            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        return $request->ip();
    }

    private function isValidIp($ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        $ip = trim($ip);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $invalidPatterns = [
            '/^127\./',
            '/^169\.254\./',
            '/^::1$/',
            '/^fc00:/',
            '/^fe80:/',
        ];

        foreach ($invalidPatterns as $pattern) {
            if (preg_match($pattern, $ip)) {
                return false;
            }
        }

        return true;
    }
}
