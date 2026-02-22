<?php

namespace Plugin\SubscriptionStatistics;

use App\Models\Log;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class Plugin extends AbstractPlugin
{
    private TelegramService $telegramService;

    /**
     * 插件启动
     */
    public function boot(): void
    {
        $this->telegramService = new TelegramService();

        $this->registerHooks();
    }

    // ==================== 钩子注册 ====================

    /**
     * 注册所有钩子
     */
    private function registerHooks(): void
    {
        // 监听订阅请求，记录到 v2_log
        $this->listen('client.subscribe.before', function () {
            $this->recordSubscriptionAccess();
        });

        // 注册 Telegram 命令
        $this->filter('telegram.bot.commands', function ($commands) {
            $commands[] = [
                'command' => '/sub',
                'description' => '订阅统计查询'
            ];
            return $commands;
        });

        // 处理 Telegram 命令
        $this->filter('telegram.message.handle', function ($handled, $data) {
            if ($handled) return $handled;

            list($msg) = $data;
            if ($msg->message_type === 'message') {
                $parsed = $this->parseSubCommand($msg->text);
                if ($parsed) {
                    list($type, $days, $limit) = $parsed;
                    $this->handleSubCommand($msg, $type, $days, $limit);
                    return true;
                }
            }

            return false;
        });
    }

    // ==================== 数据记录 ====================

    /**
     * 记录订阅访问到 v2_log
     */
    private function recordSubscriptionAccess(): void
    {
        if (!$this->getConfig('enabled', true)) {
            return;
        }

        $request = request();
        $user = $this->getUserFromRequest($request);

        $logData = $this->buildLogData($request, $user);
        $this->saveLog($logData);
    }

    /**
     * 从请求中获取用户
     */
    private function getUserFromRequest(Request $request): ?User
    {
        $user = $request->user();

        if (!$user) {
            $token = $request->input('token', $request->route('token'));
            $user = $token ? $this->findUserByToken($token) : null;
        }

        return $user;
    }

    /**
     * 构建日志数据
     */
    private function buildLogData(Request $request, ?User $user): array
    {
        $realIp = $this->getRealIpAddress($request);
        $originalIp = $request->ip();
        $ip = $this->getConfig('track_ip', true) ? $realIp : null;

        return [
            'user_email' => $user?->email,
            'ip' => $ip,
            'original_ip' => $originalIp,
            'real_ip' => $realIp,
            'ip_changed' => $originalIp !== $realIp,
            'user_agent' => $this->getConfig('track_ua', true) ? $request->header('User-Agent') : null,
            'token' => $request->input('token', $request->route('token')),
            'query_params' => $request->query()
        ];
    }

    /**
     * 保存日志到数据库
     *
     * 存储策略：
     * - data: 存储完整的日志数据（JSON格式），用于详细查询和分析
     * - context: 存储关键字段摘要（JSON格式），用于快速索引和统计
     */
    private function saveLog(array $logData): void
    {
        $log = new Log();
        $log->title = '订阅访问';
        $log->level = 'info';
        $log->host = request()->getHost();
        $log->uri = request()->path();
        $log->method = request()->method();
        $log->ip = $logData['ip'];
        $log->data = json_encode($logData);
        $log->context = json_encode($this->extractContextSummary($logData));
        $log->save();
    }

    /**
     * 提取上下文摘要
     *
     * 从完整日志数据中提取关键字段，用于快速索引和统计查询
     */
    private function extractContextSummary(array $logData): array
    {
        return [
            'user_email' => $logData['user_email'],
            'user_agent' => $logData['user_agent'],
        ];
    }

    /**
     * 通过token查找用户
     */
    private function findUserByToken($token): ?User
    {
        if (!$token) return null;
        return User::where('token', $token)->first();
    }

    // ==================== 命令处理 ====================

    /**
     * 解析订阅命令
     *
     * 支持格式：
     * - /sub - 综合报告
     * - /sub [days] - 指定天数的综合报告
     * - /sub ua - UA排行(默认20个)
     * - /sub ua [limit|days] - UA排行，数字<=30视为天数，>30视为数量
     * - /sub ua [days] [limit] - 指定天数和数量
     */
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

    /**
     * 解析带类型的命令（如 /sub ua 7 30）
     */
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

    /**
     * 解析综合报告命令（如 /sub 或 /sub 7）
     */
    private function parseSummaryCommand(array $matches): array
    {
        return [null, isset($matches[6]) ? intval($matches[6]) : 0, null];
    }

    /**
     * 验证并限制数量范围
     */
    private function validateLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }

    /**
     * 处理 /sub 命令
     */
    private function handleSubCommand($message, ?string $type = null, int $days = 0, ?int $limit = null): void
    {
        if (!$this->validateCommandAccess($message)) {
            return;
        }

        try {
            $days = max(0, min($days, 30)); // 限制最多30天
            $result = $this->generateReport($type, $days, $limit);
            $this->sendReport($message, $result, $days);

        } catch (\Exception $e) {
            $this->handleCommandError($message, $e);
        }
    }

    /**
     * 验证命令访问权限
     */
    private function validateCommandAccess($message): bool
    {
        if (!$message->is_private) return false;

        $user = User::where('telegram_id', $message->chat_id)->first();
        return $user && ($user->is_admin || $user->is_staff);
    }

    /**
     * 生成报告
     */
    private function generateReport(?string $type, int $days, ?int $limit = null): array
    {
        return match ($type) {
            'user' => $this->generateUserRankingReport($days, $limit),
            'ua' => $this->generateUaRankingReport($days, $limit),
            'ip' => $this->generateIpRankingReport($days, $limit),
            default => $this->generateSummaryReport($days),
        };
    }

    /**
     * 发送报告
     */
    private function sendReport($message, array $result, int $days): void
    {
        if ($result['has_data']) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                implode("\n", $result['report']),
                'markdown'
            );
        } else {
            $periodLabel = $this->formatPeriodLabel($days);
            $this->telegramService->sendMessage(
                $message->chat_id,
                "📊 {$periodLabel}暂无订阅访问数据",
                'markdown'
            );
        }
    }

    /**
     * 处理命令错误
     */
    private function handleCommandError($message, \Exception $e): void
    {
        \Log::error('SubscriptionStatistics command failed', [
            'error' => $e->getMessage(),
            'chat_id' => $message->chat_id,
            'command' => $message->text,
            'trace' => $e->getTraceAsString()
        ]);

        $errorMessage = "❌ 命令执行失败";
        if (app()->environment('local', 'testing')) {
            $errorMessage .= "：" . $e->getMessage();
        }

        $this->telegramService->sendMessage($message->chat_id, $errorMessage);
    }

    // ==================== 报告生成 ====================

    /**
     * 生成综合统计报告（默认显示）
     */
    private function generateSummaryReport(int $days = 0): array
    {
        $subscriptionLogs = $this->getSubscriptionLogs($days);
        if ($subscriptionLogs->isEmpty()) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);

        $stats = $this->calculateBasicStats($subscriptionLogs, $days);
        $uaRanking = $this->getUARanking($subscriptionLogs, $days);
        $userRanking = $this->getUserRanking($subscriptionLogs, $days);
        $ipRanking = $this->getIPRanking($subscriptionLogs, $days);

        $report = $this->buildSummaryReport($periodLabel, $stats, $uaRanking, $userRanking, $ipRanking);

        return ['has_data' => true, 'report' => $report];
    }

    /**
     * 构建综合报告内容
     */
    private function buildSummaryReport(string $periodLabel, array $stats, $uaRanking, $userRanking, $ipRanking): array
    {
        $report = [
            "📊 订阅访问统计分析",
            "时段：{$periodLabel}",
            "📈 总访问{$stats['totalAccess']}次 | {$stats['uniqueUsers']}用户 | 用户平均IP{$stats['avgIPPerUser']} | 用户平均UA{$stats['avgUAPerUser']}",
            "══════════════════════════",
            "",
            "👥 用户排行 TOP 5：",
            "══════════════════════════",
            "💡 使用 `/sub user` 查看更多"
        ];

        foreach ($userRanking->take(5) as $index => $user) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($user['count']);
            $report[] = "{$rank}. `{$user['email']}`：{$user['count']} 次 {$frequencyIcon}";
        }

        $report[] = "";
        $report[] = "🌐 IP访问排行 TOP 5：";
        $report[] = "══════════════════════════";
        $report[] = "💡 使用 `/sub ip` 查看更多";

        foreach ($ipRanking->take(5) as $index => $ip) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($ip['count']);
            $report[] = "{$rank}. `{$ip['ip']}`：{$ip['count']} 次 {$frequencyIcon}";
            $report[] = "    └ {$ip['unique_users']} 用户 | {$ip['unique_uas']} 种客户端";
        }

        $report[] = "";
        $report[] = "📱 UA排行 TOP 5：";
        $report[] = "══════════════════════════";
        $report[] = "💡 使用 `/sub ua` 查看更多";

        foreach ($uaRanking->take(5) as $index => $ua) {
            $rank = $index + 1;
            $report[] = "{$rank}. `{$ua['ua']}`：{$ua['count']} 次 ({$ua['users']} 用户)";
        }

        return $report;
    }

    /**
     * 生成用户拉取频率排行报告
     */
    private function generateUserRankingReport(int $days = 0, ?int $limit = 20): array
    {
        $subscriptionLogs = $this->getSubscriptionLogs($days);
        if ($subscriptionLogs->isEmpty()) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $userRanking = $this->getUserRanking($subscriptionLogs, $days);

        $report = [
            "👥 用户排行 TOP {$limit} 💡 使用 `/sub user {$limit}` 查看更多",
            "时段：{$periodLabel}",
            "══════════════════════════"
        ];

        foreach ($userRanking->take($limit) as $index => $user) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($user['count']);
            $report[] = "{$rank}. `{$user['email']}`：{$user['count']} 次 {$frequencyIcon}";
        }

        return ['has_data' => true, 'report' => $report];
    }

    /**
     * 生成UA排行报告
     */
    private function generateUaRankingReport(int $days = 0, ?int $limit = 20): array
    {
        $subscriptionLogs = $this->getSubscriptionLogs($days);
        if ($subscriptionLogs->isEmpty()) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $uaRanking = $this->getUARanking($subscriptionLogs, $days);

        $report = [
            "📱 UA排行 TOP {$limit} 💡 使用 `/sub ua {$limit}` 查看更多",
            "时段：{$periodLabel}",
            "══════════════════════════"
        ];

        foreach ($uaRanking->take($limit) as $index => $ua) {
            $rank = $index + 1;
            $report[] = "{$rank}. `{$ua['ua']}`：{$ua['count']} 次 ({$ua['users']} 用户)";
        }

        return ['has_data' => true, 'report' => $report];
    }

    /**
     * 生成IP访问排行报告
     */
    private function generateIpRankingReport(int $days = 0, ?int $limit = 20): array
    {
        $subscriptionLogs = $this->getSubscriptionLogs($days);
        if ($subscriptionLogs->isEmpty()) {
            return ['has_data' => false, 'report' => []];
        }

        $periodLabel = $this->formatPeriodLabel($days);
        $ipRanking = $this->getIPRanking($subscriptionLogs, $days);

        $report = [
            "🌐 IP访问排行 TOP {$limit} 💡 使用 `/sub ip {$limit}` 查看更多",
            "时段：{$periodLabel}",
            "══════════════════════════"
        ];

        foreach ($ipRanking->take($limit) as $index => $ip) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($ip['count']);
            $report[] = "{$rank}. `{$ip['ip']}`：{$ip['count']} 次 {$frequencyIcon}";
            $report[] = "    └ {$ip['unique_users']} 用户 | {$ip['unique_uas']} 种客户端";
        }

        return ['has_data' => true, 'report' => $report];
    }

    // ==================== 数据处理 ====================

    /**
     * 获取订阅访问日志
     */
    private function getSubscriptionLogs(int $days = 0): \Illuminate\Database\Eloquent\Collection
    {
        $timeRange = $this->getTimeRange($days);

        return Log::where('title', '订阅访问')
            ->where('created_at', '>=', $timeRange['startAt'])
            ->where('created_at', '<', $timeRange['endAt'])
            ->select(['id', 'ip', 'context', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * 计算基础统计
     */
    private function calculateBasicStats(\Illuminate\Database\Eloquent\Collection $logs, int $days = 0): array
    {
        if ($logs->isEmpty()) {
            return [
                'totalAccess' => 0,
                'uniqueUsers' => 0,
                'avgIPPerUser' => 0,
                'avgUAPerUser' => 0,
            ];
        }

        $totalAccess = $logs->count();

        $uniqueUsers = $logs->pluck('context')
            ->map(fn($context) => json_decode($context, true)['user_email'] ?? null)
            ->filter()
            ->unique()
            ->count();

        $uniqueIPs = $logs->pluck('ip')->filter()->unique()->count();

        $uniqueUAs = $logs->pluck('context')
            ->map(function ($context) {
                $data = json_decode($context, true);
                return $this->parseUserAgent($data['user_agent'] ?? '');
            })
            ->filter()
            ->unique()
            ->count();

        return [
            'totalAccess' => $totalAccess,
            'uniqueUsers' => $uniqueUsers,
            'avgIPPerUser' => round($uniqueIPs / max($uniqueUsers, 1), 1),
            'avgUAPerUser' => round($uniqueUAs / max($uniqueUsers, 1), 1),
        ];
    }

    /**
     * 获取客户端排行
     */
    private function getUARanking(\Illuminate\Database\Eloquent\Collection $logs, int $days = 0): \Illuminate\Support\Collection
    {
        if ($logs->isEmpty()) {
            return collect([]);
        }

        return $logs->map(function ($log) {
            $context = json_decode($log->context, true);
            return [
                'ua' => $this->parseUserAgent($context['user_agent'] ?? ''),
                'user_email' => $context['user_email'] ?? null
            ];
        })
        ->groupBy('ua')
        ->map(function ($group) {
            return [
                'ua' => $group->first()['ua'],
                'count' => $group->count(),
                'users' => $group->pluck('user_email')->filter()->unique()->count()
            ];
        })
        ->sortByDesc('count')
        ->values();
    }

    /**
     * 获取用户排行
     */
    private function getUserRanking(\Illuminate\Database\Eloquent\Collection $logs, int $days = 0): \Illuminate\Support\Collection
    {
        if ($logs->isEmpty()) {
            return collect([]);
        }

        return $logs->map(function ($log) {
            $context = json_decode($log->context, true);
            return [
                'email' => $context['user_email'] ?? '未知用户',
                'count' => 1
            ];
        })
        ->groupBy('email')
        ->map(function ($group) {
            return [
                'email' => $group->first()['email'],
                'count' => $group->count()
            ];
        })
        ->sortByDesc('count')
        ->values();
    }

    /**
     * 获取IP排行
     */
    private function getIPRanking(\Illuminate\Database\Eloquent\Collection $logs, int $days = 0): \Illuminate\Support\Collection
    {
        if ($logs->isEmpty()) {
            return collect([]);
        }

        return $logs->map(function ($log) {
            $context = json_decode($log->context, true);
            return [
                'ip' => $log->ip,
                'user_email' => $context['user_email'] ?? null,
                'ua' => $this->parseUserAgent($context['user_agent'] ?? '')
            ];
        })
        ->groupBy('ip')
        ->map(function ($group) {
            return [
                'ip' => $group->first()['ip'],
                'count' => $group->count(),
                'unique_users' => $group->pluck('user_email')->filter()->unique()->count(),
                'unique_uas' => $group->pluck('ua')->unique()->count()
            ];
        })
        ->sortByDesc('count')
        ->values();
    }

    // ==================== 工具方法 ====================

    /**
     * 格式化时间段标签
     */
    private function formatPeriodLabel(int $days): string
    {
        $timeRange = $this->getTimeRange($days);
        $start = date('Y-m-d H:i', $timeRange['startAt']);
        $end = date('Y-m-d H:i', $timeRange['endAt']);
        return "{$start} ~ {$end}";
    }

    /**
     * 获取频率图标
     */
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

    /**
     * 解析User-Agent
     */
    private function parseUserAgent($userAgent): string
    {
        if (empty($userAgent)) return '无UA';

        // 提取第一个单词作为主要标识符，去掉 / 后面的所有内容
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9\-_]*)/', $userAgent, $matches)) {
            $identifier = $matches[1];
            return substr($identifier, 0, 30);
        }

        return '解析失败';
    }

    /**
     * 获取时间范围
     */
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

    /**
     * 获取真实 IP 地址（支持各种 CDN）
     */
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

    /**
     * 验证 IP 地址是否有效
     */
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