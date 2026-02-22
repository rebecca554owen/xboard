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
            if ($msg->message_type === 'message' && $this->parseSubCommand($msg->text)) {
                list($type, $days, $limit) = $this->parseSubCommand($msg->text);
                $this->handleSubCommand($msg, $type, $days, $limit);
                return true;
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
        $log->context = json_encode($logData);
        $log->save();
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
     */
    private function parseSubCommand(string $text): ?array
    {
        // 支持格式：
        // /sub - 综合报告
        // /sub ua - UA排行(默认20个)
        // /sub ua 30 - UA排行30个
        // /sub ua 7 30 - 7天内UA排行30个
        if (!preg_match('/^\/sub(\s+(user|ua|ip)(?:\s+(\d+)(?:\s+(\d+))?)?)?(\s+(\d+))?$/', $text, $matches)) {
            return null;
        }

        $type = $matches[2] ?? null;

        if ($type) {
            // 有指定类型的命令
            $days = 0;
            $limit = 20; // 默认数量

            if (isset($matches[3]) && isset($matches[4])) {
                // 格式：/sub ua 7 30 (7天，30个)
                $days = intval($matches[3]);
                $limit = intval($matches[4]);
            } elseif (isset($matches[3])) {
                // 格式：/sub ua 30 或 /sub ua 7
                $num = intval($matches[3]);
                if ($num <= 30) {
                    // 如果数字<=30，认为是天数（因为天数限制为30）
                    $days = $num;
                } else {
                    // 如果数字>30，认为是数量
                    $limit = $num;
                }
            }

            $limit = max(1, min($limit, 100)); // 限制数量在1-100之间
            return [$type, $days, $limit];
        } else {
            // 综合报告命令，可能带天数
            $days = isset($matches[5]) ? intval($matches[5]) : 0;
            return [null, $days, null];
        }
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
            $periodLabel = $this->getPeriodLabel($days);
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

        $timeRange = $this->getTimeRange($days);
        $periodLabel = $this->formatTimeRangeLabel($timeRange);

        // 获取数据
        $stats = $this->calculateBasicStats($subscriptionLogs);
        $uaRanking = $this->getUARanking($subscriptionLogs);
        $userRanking = $this->getUserRanking($subscriptionLogs);
        $ipRanking = $this->getIPRanking($subscriptionLogs);

        // 构建报告
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

        // 添加用户排行
        foreach ($userRanking->take(5) as $index => $user) {
            $rank = $index + 1;
            $frequencyIcon = $this->getFrequencyIcon($user['count']);
            $report[] = "{$rank}. `{$user['email']}`：{$user['count']} 次 {$frequencyIcon}";
        }

        $report[] = "";
        $report[] = "🌐 IP访问排行 TOP 5：";
        $report[] = "══════════════════════════";
        $report[] = "💡 使用 `/sub ip` 查看更多";

        // 添加IP排行
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

        // 添加客户端排行
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

        $timeRange = $this->getTimeRange($days);
        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $userRanking = $this->getUserRanking($subscriptionLogs);

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

        $timeRange = $this->getTimeRange($days);
        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $uaRanking = $this->getUARanking($subscriptionLogs);

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

        $timeRange = $this->getTimeRange($days);
        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $ipRanking = $this->getIPRanking($subscriptionLogs);

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
            ->get();
    }

    /**
     * 计算基础统计
     */
    private function calculateBasicStats(\Illuminate\Database\Eloquent\Collection $logs): array
    {
        $totalAccess = $logs->count();
        $uniqueUsers = $logs->pluck('context')
            ->map(fn($context) => json_decode($context, true)['user_email'] ?? null)
            ->filter()
            ->unique()
            ->count();
        $uniqueIPs = $logs->pluck('ip')->unique()->count();
        $uniqueUAs = $logs->map(function ($log) {
            $context = json_decode($log->context, true);
            return $this->parseUserAgent($context['user_agent'] ?? '');
        })->unique()->count();

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
    private function getUARanking(\Illuminate\Database\Eloquent\Collection $logs): \Illuminate\Support\Collection
    {
        return collect($logs)
            ->map(function ($log) {
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
    private function getUserRanking(\Illuminate\Database\Eloquent\Collection $logs): \Illuminate\Support\Collection
    {
        return collect($logs)
            ->map(function ($log) {
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
    private function getIPRanking(\Illuminate\Database\Eloquent\Collection $logs): \Illuminate\Support\Collection
    {
        return collect($logs)
            ->map(function ($log) {
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
     * 获取时间段标签
     */
    private function getPeriodLabel(int $days): string
    {
        return match ($days) {
            0 => '今日',
            1 => '昨日',
            default => "最近{$days}天"
        };
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
     * 获取时间范围（参考 Baobiao 插件）
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
     * 格式化时间范围标签
     */
    private function formatTimeRangeLabel(array $timeRange): string
    {
        $start = date('Y-m-d H:i', $timeRange['startAt']);
        $end = date('Y-m-d H:i', $timeRange['endAt']);
        return "{$start} ~ {$end}";
    }

    /**
     * 获取真实 IP 地址（支持各种 CDN）
     */
    private function getRealIpAddress(Request $request): string
    {
        // 检查各种 CDN 头信息，按优先级顺序
        $headers = [
            'CF-Connecting-IP',        // Cloudflare
            'True-Client-IP',          // Cloudflare Enterprise
            'X-Real-IP',               // Nginx
            'X-Forwarded-For',         // 标准代理头
            'HTTP_X_FORWARDED_FOR',    // 某些服务器的变体
            'HTTP_X_REAL_IP',          // 某些服务器的变体
            'X-Cluster-Client-IP',     // 集群环境
            'X-Original-Forwarded-For', // 某些负载均衡器
            'HTTP_CLIENT_IP',          // 某些环境
            'WL-Proxy-Client-IP',      // WebLogic
        ];

        foreach ($headers as $header) {
            $ip = $request->header($header);
            if ($this->isValidIp($ip)) {
                // X-Forwarded-For 可能包含多个 IP，取第一个
                if (strtolower($header) === 'x-forwarded-for') {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                    if ($this->isValidIp($ip)) {
                        return $ip;
                    }
                } else {
                    return $ip;
                }
            }
        }

        // 如果没有找到代理头，使用默认方法
        return $request->ip();
    }

    /**
     * 验证 IP 地址是否有效
     */
    private function isValidIp($ip): bool
    {
        if (!$ip || empty(trim($ip))) {
            return false;
        }

        $ip = trim($ip);

        // 过滤掉内网 IP 和无效 IP
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // 过滤掉一些常见的无效值
        $invalidPatterns = [
            '/^127\./',           // localhost
            '/^169\.254\./',      // 链路本地地址
            '/^::1$/',            // IPv6 localhost
            '/^fc00:/',           // IPv6 私有地址
            '/^fe80:/',           // IPv6 链路本地地址
        ];

        foreach ($invalidPatterns as $pattern) {
            if (preg_match($pattern, $ip)) {
                return false;
            }
        }

        return true;
    }
}