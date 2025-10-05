<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Del extends Start
{
    public $command = '/del';
    public $description = '删除无效或未续费用户';

    private const DEFAULT_REGISTER_DAYS = 7;
    private const DEFAULT_EXPIRED_DAYS = 30;
    private const BATCH_SIZE = 100;
    private const SAMPLE_LIMIT = 3;

    public function handle($message, $match = [])
    {
        if (!$this->ensureAuthorized($message)) {
            return;
        }

        $args = $message->args ?? [];
        $mode = strtolower($args[0] ?? '');

        if ($mode === 'invalid') {
            $this->handleInvalid($message, array_slice($args, 1));
            return;
        }

        if ($mode === 'expired') {
            $this->handleExpired($message, array_slice($args, 1));
            return;
        }

        // 默认显示统计信息
        $this->handleStats($message, $args);
    }

    private function handleInvalid($message, array $args): void
    {
        $registerDays = self::DEFAULT_REGISTER_DAYS;
        $confirm = false;

        // 解析参数
        foreach ($args as $arg) {
            if ($arg === '-y') {
                $confirm = true;
                continue;
            }

            if (is_numeric($arg)) {
                $registerDays = max(1, (int) $arg);
            }
        }

        try {
            $count = $this->countInvalidUsers($registerDays);
            $samples = $this->collectUserSamples($registerDays, self::SAMPLE_LIMIT, null);

            if (!$confirm) {
                $report = [
                    '🧹 无效用户预览',
                    "📅 注册天数阈值：{$registerDays} 天",
                    '────────────────────',
                    "📊 统计数量：{$count} 个",
                ];

                if ($count > 0 && !empty($samples)) {
                    $report[] = '示例：';
                    foreach ($samples as $sample) {
                        $report[] = "  • {$sample}";
                    }
                }

                $report[] = '────────────────────';
                $report[] = '如需删除请追加 `-y`，例如：`/del invalid ' . $registerDays . ' -y`';

                $this->telegramService->sendMessage(
                    $message->chat_id,
                    implode("\n", $report),
                    'markdown'
                );

                return;
            }

            // 发送处理中提示
            $this->telegramService->sendMessage($message->chat_id, '⏳ 正在删除无效用户...', 'markdown');

            $deleted = $this->deleteCandidates($registerDays, null);

            $report = [
                '✅ 无效用户清理完成',
                "📅 注册天数阈值：{$registerDays} 天",
                '────────────────────',
                "🗑️ 删除无效用户：{$deleted} 个",
            ];

            if ($deleted === 0) {
                $report[] = '无可删除用户。';
            }

            $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
        } catch (\Throwable $exception) {
            Log::error('处理无效用户清理失败', [
                'error' => $exception->getMessage(),
                'register_days' => $registerDays,
                'confirm' => $confirm,
            ]);

            $this->telegramService->sendMessage($message->chat_id, '❌ 操作失败，请检查日志');
        }
    }

    private function handleExpired($message, array $args): void
    {
        $registerDays = self::DEFAULT_REGISTER_DAYS;
        $expiredDays = self::DEFAULT_EXPIRED_DAYS;
        $confirm = false;

        // 解析参数
        foreach ($args as $arg) {
            if ($arg === '-y') {
                $confirm = true;
                continue;
            }

            if (is_numeric($arg)) {
                $expiredDays = max(0, (int) $arg);
            }
        }

        try {
            $count = $this->countExpiredUsers($registerDays, $expiredDays);
            $samples = $this->collectUserSamples($registerDays, self::SAMPLE_LIMIT, $expiredDays);

            if (!$confirm) {
                $report = [
                    '🧹 未续费用户预览',
                    "📅 注册天数阈值：{$registerDays} 天",
                    "⏰ 过期天数阈值：{$expiredDays} 天",
                    '────────────────────',
                    "📊 统计数量：{$count} 个",
                ];

                if ($count > 0 && !empty($samples)) {
                    $report[] = '示例：';
                    foreach ($samples as $sample) {
                        $report[] = "  • {$sample}";
                    }
                }

                $report[] = '────────────────────';
                $report[] = '如需删除请追加 `-y`，例如：`/del expired ' . $expiredDays . ' -y`';

                $this->telegramService->sendMessage(
                    $message->chat_id,
                    implode("\n", $report),
                    'markdown'
                );

                return;
            }

            // 发送处理中提示
            $this->telegramService->sendMessage($message->chat_id, '⏳ 正在删除未续费用户...', 'markdown');

            $deleted = $this->deleteCandidates($registerDays, $expiredDays);

            $report = [
                '✅ 未续费用户清理完成',
                "📅 注册天数阈值：{$registerDays} 天",
                "⏰ 过期天数阈值：{$expiredDays} 天",
                '────────────────────',
                "🗑️ 删除未续费用户：{$deleted} 个",
            ];

            if ($deleted === 0) {
                $report[] = '无可删除用户。';
            }

            $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
        } catch (\Throwable $exception) {
            Log::error('处理未续费用户清理失败', [
                'error' => $exception->getMessage(),
                'register_days' => $registerDays,
                'expired_days' => $expiredDays,
                'confirm' => $confirm,
            ]);

            $this->telegramService->sendMessage($message->chat_id, '❌ 操作失败，请检查日志');
        }
    }

    private function handleStats($message, array $args): void
    {
        try {
            $registerDays = self::DEFAULT_REGISTER_DAYS;
            $expiredDays = self::DEFAULT_EXPIRED_DAYS;

            // 统计注册天数大于7天的无效用户
            $invalidCount = $this->countInvalidUsers($registerDays);
            // 统计未续费天数大于30天的用户
            $expiredCount = $this->countExpiredUsers($registerDays, $expiredDays);

            $invalidSamples = $this->collectUserSamples($registerDays, self::SAMPLE_LIMIT, null);
            $expiredSamples = $this->collectUserSamples($registerDays, self::SAMPLE_LIMIT, $expiredDays);

            $report = [
                '🧹 用户清理统计',
                '────────────────────',
                "📊 注册天数大于{$registerDays}天无效用户：{$invalidCount} 个",
            ];

            if ($invalidCount > 0 && !empty($invalidSamples)) {
                $report[] = '无效用户示例：';
                foreach ($invalidSamples as $sample) {
                    $report[] = "  • {$sample}";
                }
            }

            $report[] = "📊 未续费天数大于{$expiredDays}天用户：{$expiredCount} 个";

            if ($expiredCount > 0 && !empty($expiredSamples)) {
                $report[] = '未续费用户示例：';
                foreach ($expiredSamples as $sample) {
                    $report[] = "  • {$sample}";
                }
            }

            $report[] = '────────────────────';
            $report[] = '操作命令：';
            $report[] = '• `/del invalid [天数]` - 预览无效用户';
            $report[] = '• `/del invalid [天数] -y` - 删除无效用户';
            $report[] = '';
            $report[] = '• `/del expired [天数]` - 预览未续费用户';
            $report[] = '• `/del expired [天数] -y` - 删除未续费用户';

            $this->telegramService->sendMessage(
                $message->chat_id,
                implode("\n", $report),
                'markdown'
            );
        } catch (\Throwable $exception) {
            Log::error('统计待删除用户失败', [
                'error' => $exception->getMessage(),
            ]);

            $this->telegramService->sendMessage($message->chat_id, '❌ 统计失败，请稍后重试');
        }
    }




    private function collectUserSamples(int $registerDays, int $limit, ?int $expiredDays): array
    {
        return $this->queryCandidateUsers($registerDays, $limit, $expiredDays)
            ->map(function (User $user) use ($expiredDays) {
                $registeredDays = floor((time() - $user->created_at) / 86400);

                if ($expiredDays !== null) {
                    // 未续费用户信息
                    $expiredDaysAgo = floor((time() - $user->expired_at) / 86400);
                    return sprintf(
                        '`%s`(注册%d天, 过期%d天)',
                        $user->email,
                        $registeredDays,
                        $expiredDaysAgo
                    );
                } else {
                    // 无效用户信息
                    return sprintf(
                        '`%s`(注册%d天)',
                        $user->email,
                        $registeredDays
                    );
                }
            })
            ->toArray();
    }


    /**
     * @return Collection<int, User>
     */
    private function queryCandidateUsers(int $registerDays, int $limit, ?int $expiredDays): Collection
    {
        $query = User::query()
            ->where('is_admin', false);

        if ($expiredDays !== null) {
            $query->where('expired_at', '>', 0)
                ->where('balance', 0)
                ->where('commission_balance', 0)
                ->where('created_at', '<', time() - ($registerDays * 86400));

            if ($expiredDays > 0) {
                $query->where('expired_at', '<', time() - ($expiredDays * 86400));
            } else {
                $query->where('expired_at', '<', time());
            }
        } else {
            $query->whereNull('plan_id')
                ->where('transfer_enable', 0)
                ->where('expired_at', 0)
                ->where('balance', 0)
                ->where('commission_balance', 0)
                ->whereNotIn('id', User::query()
                    ->whereNotNull('invite_user_id')
                    ->select('invite_user_id')
                )
                ->where('created_at', '<', time() - ($registerDays * 86400));
        }

        return $query->limit($limit)->get();
    }

    private function deleteCandidates(int $registerDays, ?int $expiredDays): int
    {
        $deleted = 0;

        while (true) {
            $candidates = $this->queryCandidateUsers($registerDays, self::BATCH_SIZE, $expiredDays);
            if ($candidates->isEmpty()) {
                break;
            }

            try {
                DB::beginTransaction();

                foreach ($candidates as $user) {
                    $this->deleteUserWithoutTransaction($user);
                    ++$deleted;
                }

                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();
                Log::error('批次删除用户失败', [
                    'batch_size' => $candidates->count(),
                    'error' => $exception->getMessage()
                ]);
            }

            if ($candidates->count() < self::BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    private function countInvalidUsers(int $registerDays): int
    {
        return User::query()
            ->where('is_admin', false)
            ->whereNull('plan_id')
            ->where('transfer_enable', 0)
            ->where('expired_at', 0)
            ->where('balance', 0)
            ->where('commission_balance', 0)
            ->whereNotIn('id', User::query()
                ->whereNotNull('invite_user_id')
                ->select('invite_user_id')
            )
            ->where('created_at', '<', time() - ($registerDays * 86400))
            ->count();
    }

    private function countExpiredUsers(int $registerDays, int $expiredDays): int
    {
        $query = User::query()
            ->where('is_admin', false)
            ->where('expired_at', '>', 0)
            ->where('balance', 0)
            ->where('commission_balance', 0)
            ->where('created_at', '<', time() - ($registerDays * 86400));

        if ($expiredDays > 0) {
            $query->where('expired_at', '<', time() - ($expiredDays * 86400));
        } else {
            $query->where('expired_at', '<', time());
        }

        return $query->count();
    }

    private function deleteUser(User $user): bool
    {
        try {
            DB::beginTransaction();

            $this->deleteUserWithoutTransaction($user);

            DB::commit();

            return true;
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('删除用户失败', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteUserWithoutTransaction(User $user): void
    {
        $user->orders()->delete();
        $user->codes()->delete();
        $user->stat()->delete();
        $user->tickets()->delete();
        $user->delete();

        Log::warning(sprintf(
            '已通过Telegram命令删除用户 ID=%d, Email=%s, 注册时间=%s',
            $user->id,
            $user->email,
            date('Y-m-d H:i:s', $user->created_at)
        ));
    }

}
