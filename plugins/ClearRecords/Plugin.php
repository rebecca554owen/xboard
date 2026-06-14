<?php

namespace Plugin\ClearRecords;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const LOCK_KEY = 'plugin:clear_records:lock';
    private const LOCK_TTL = 300;

    private ?TelegramService $telegramService = null;

    public function boot(): void
    {
        $this->filter('telegram.bot.commands', function ($commands) {
            $commands[] = [
                'command' => '/clear',
                'description' => '清理订单/工单记录',
            ];

            return $commands;
        });

        $this->filter('telegram.message.handle', function ($handled, $data) {
            if ($handled) {
                return $handled;
            }

            if (!$this->getConfig('enable_command', true)) {
                return false;
            }

            [$msg] = $data;
            if ($msg->message_type !== 'message') {
                return false;
            }

            $parsed = $this->parseCommand((string) ($msg->text ?? ''));
            if (!$parsed) {
                return false;
            }

            $this->handleClearCommand($msg, $parsed['target'], $parsed['confirmed']);

            return true;
        });
    }

    private function telegram(): TelegramService
    {
        return $this->telegramService ??= new TelegramService();
    }

    /**
     * @return array{target: string|null, confirmed: bool}|null
     */
    private function parseCommand(string $text): ?array
    {
        $text = trim($text);
        if (!preg_match('/^\/clear(?:\s+(order|ticket)(?:\s+(-y))?)?$/', $text, $matches)) {
            return null;
        }

        return [
            'target' => $matches[1] ?? null,
            'confirmed' => isset($matches[2]) && $matches[2] === '-y',
        ];
    }

    private function handleClearCommand($message, ?string $target, bool $confirmed): void
    {
        if (!$message->is_private) {
            return;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user || !$user->is_admin) {
            $this->telegram()->sendMessage($message->chat_id, '无权限执行清理命令', 'markdown');
            return;
        }

        try {
            if ($target === null) {
                $this->sendUsage($message->chat_id);
                return;
            }

            if (!$confirmed) {
                $this->sendPreview($message->chat_id, $target);
                return;
            }

            $this->executeClear($message->chat_id, $target, $user);
        } catch (\Throwable $exception) {
            Log::error('ClearRecords command failed', [
                'chat_id' => $message->chat_id,
                'target' => $target,
                'confirmed' => $confirmed,
                'error' => $exception->getMessage(),
            ]);

            $this->telegram()->sendMessage($message->chat_id, '命令执行失败：' . $exception->getMessage());
        }
    }

    private function sendUsage(int $chatId): void
    {
        $message = implode("\n", [
            '清理记录命令',
            '———————————————',
            '查看订单记录数量：`/clear order`',
            '删除全部订单记录：`/clear order -y`',
            '',
            '查看工单记录数量：`/clear ticket`',
            '删除全部工单记录：`/clear ticket -y`',
        ]);

        $this->telegram()->sendMessage($chatId, $message, 'markdown');
    }

    private function sendPreview(int $chatId, string $target): void
    {
        if ($target === 'order') {
            $orderCount = Order::count();
            $this->telegram()->sendMessage(
                $chatId,
                "当前订单记录：`{$orderCount}` 条\n确认删除请发送：`/clear order -y`",
                'markdown'
            );
            return;
        }

        $ticketCount = Ticket::count();
        $messageCount = TicketMessage::count();

        $this->telegram()->sendMessage(
            $chatId,
            "当前工单记录：`{$ticketCount}` 条\n当前工单消息：`{$messageCount}` 条\n确认删除请发送：`/clear ticket -y`",
            'markdown'
        );
    }

    private function executeClear(int $chatId, string $target, User $operator): void
    {
        if (!Cache::add(self::LOCK_KEY, 1, self::LOCK_TTL)) {
            $this->telegram()->sendMessage($chatId, '清理任务正在运行，请稍后再试');
            return;
        }

        try {
            $result = $target === 'order'
                ? $this->clearOrders()
                : $this->clearTickets();

            Log::warning('ClearRecords command completed', [
                'operator_id' => $operator->id,
                'operator_email' => $operator->email,
                'target' => $target,
                'result' => $result,
            ]);

            $this->sendResult($chatId, $target, $result);
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /**
     * @return array{orders: int}
     */
    private function clearOrders(): array
    {
        return DB::transaction(function () {
            $orderCount = Order::count();
            Order::query()->delete();

            return [
                'orders' => $orderCount,
            ];
        });
    }

    /**
     * @return array{tickets: int, messages: int}
     */
    private function clearTickets(): array
    {
        return DB::transaction(function () {
            $ticketCount = Ticket::count();
            $messageCount = TicketMessage::count();

            TicketMessage::query()->delete();
            Ticket::query()->delete();

            return [
                'tickets' => $ticketCount,
                'messages' => $messageCount,
            ];
        });
    }

    private function sendResult(int $chatId, string $target, array $result): void
    {
        if ($target === 'order') {
            $this->telegram()->sendMessage($chatId, "订单记录清理完成：已删除 `{$result['orders']}` 条", 'markdown');
            return;
        }

        $this->telegram()->sendMessage(
            $chatId,
            "工单记录清理完成：已删除工单 `{$result['tickets']}` 条，工单消息 `{$result['messages']}` 条",
            'markdown'
        );
    }
}
