<?php

namespace Plugin\CustomTrafficReset;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\Plugin\AbstractPlugin;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Throwable;

class Plugin extends AbstractPlugin
{
    private const SYNC_CHUNK_SIZE = 200;

    private const SCENARIO_NEW_PURCHASE = 'new_purchase';
    private const SCENARIO_EXPIRED_REPURCHASE = 'expired_repurchase';
    private const SCENARIO_RENEWAL = 'renewal';
    private const SCENARIO_PLAN_CHANGE = 'plan_change';

    /**
     * 缓存订单开启前的用户状态，便于 after 钩子判断场景。
     *
     * @var array<int, array<string, mixed>>
     */
    private array $orderSnapshots = [];

    public function boot(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->listen('order.open.before', [$this, 'handleOrderOpenBefore']);
        $this->listen('order.open.after', [$this, 'handleOrderOpenAfter']);
        $this->listen('traffic.reset.after', [$this, 'handleTrafficResetAfter']);
    }

    public function schedule(Schedule $schedule): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $interval = (int) $this->getConfig('sync_interval_minutes', 0);
        if ($interval <= 0) {
            return;
        }

        $interval = max(1, min($interval, 60));

        $schedule->call(function () {
            $this->syncCustomResetTimes();
        })->cron("*/{$interval} * * * *")->name('custom_traffic_reset.sync_next_reset')->onOneServer();
    }

    public function handleOrderOpenBefore(Order $order): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = $this->resolveUser($order);
        if (!$user instanceof User) {
            return;
        }

        $this->orderSnapshots[$order->id] = [
            'plan_id' => $user->plan_id,
            'expired_at' => $user->expired_at,
            'next_reset_at' => $user->next_reset_at,
            'has_plan' => (bool) $user->plan_id,
            'expired' => $user->expired_at !== null && $user->expired_at <= time(),
        ];
    }

    public function handleOrderOpenAfter(Order $order): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $user = $this->resolveUser($order);
        $plan = $this->resolvePlan($order);
        if (!$user instanceof User || !$plan instanceof Plan) {
            return;
        }

        $snapshot = $this->orderSnapshots[$order->id] ?? null;
        $scenario = $this->determineScenario($order, $user, $snapshot);
        $months = $this->resolvePeriodMonths($order);
        $intervalDays = $this->parseIntervalDays($plan);
        $originalNextResetAt = $user->next_reset_at;
        $nextResetChanged = false;

        try {
            if ($months !== null) {
                $user->expired_at = $this->calculateExpiredAt($scenario, $months, $snapshot);
            }

            if ($intervalDays !== null) {
                $nextResetAt = $this->calculateNextResetAtAfterOrder($scenario, $intervalDays, $snapshot, $user->expired_at);
                if ($nextResetAt !== null && $nextResetAt !== $originalNextResetAt) {
                    $user->next_reset_at = $nextResetAt;
                    $nextResetChanged = true;
                }
            }

            if ($user->isDirty()) {
                $user->save();
            }

            if ($nextResetChanged) {
                Log::info('custom_traffic_reset.next_reset_updated', [
                    'user_id' => $user->id,
                    'source' => 'order_open',
                    'from' => $originalNextResetAt,
                    'to' => $user->next_reset_at,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('custom_traffic_reset.order_open_after_failed', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            unset($this->orderSnapshots[$order->id]);
        }
    }

    public function handleTrafficResetAfter(User $user): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $plan = $user->plan ?: ($user->plan_id ? Plan::find($user->plan_id) : null);
        $intervalDays = $this->parseIntervalDays($plan);
        if ($intervalDays === null) {
            return;
        }

        $originalNextResetAt = $user->next_reset_at;
        $nextResetAt = $this->calculateNextResetAfterTrafficReset($intervalDays, $user->expired_at);
        if ($nextResetAt === null || $nextResetAt === $originalNextResetAt) {
            return;
        }

        $user->next_reset_at = $nextResetAt;
        $user->save();

        Log::info('custom_traffic_reset.next_reset_updated', [
            'user_id' => $user->id,
            'source' => 'traffic_reset',
            'from' => $originalNextResetAt,
            'to' => $user->next_reset_at,
        ]);
    }

    private function syncCustomResetTimes(): void
    {
        $timezone = config('app.timezone');

        User::query()
            ->whereNotNull('plan_id')
            ->with('plan')
            ->chunkById(self::SYNC_CHUNK_SIZE, function ($users) use ($timezone) {
                foreach ($users as $user) {
                    if (!$user instanceof User) {
                        continue;
                    }

                    $plan = $user->plan;
                    $intervalDays = $this->parseIntervalDays($plan);
                    if ($intervalDays === null) {
                        continue;
                    }

                    $now = Carbon::now($timezone);

                    if ($user->expired_at !== null && $user->expired_at <= $now->timestamp) {
                        continue;
                    }

                    $expected = $this->calculateExpectedNextResetForSync($user, $intervalDays, $now);
                    if ($expected === null || $expected === $user->next_reset_at) {
                        continue;
                    }

                    $originalNextResetAt = $user->next_reset_at;
                    $user->next_reset_at = $expected;
                    $user->save();

                    Log::info('custom_traffic_reset.next_reset_updated', [
                        'user_id' => $user->id,
                        'source' => 'sync',
                        'from' => $originalNextResetAt,
                        'to' => $user->next_reset_at,
                    ]);
                }
            });
    }

    private function isEnabled(): bool
    {
        return (bool) $this->getConfig('enabled', true);
    }

    private function resolveUser(Order $order): ?User
    {
        if ($order->relationLoaded('user') && $order->user instanceof User) {
            return $order->user;
        }

        return $order->user_id ? User::find($order->user_id) : null;
    }

    private function resolvePlan(Order $order): ?Plan
    {
        if ($order->relationLoaded('plan') && $order->plan instanceof Plan) {
            return $order->plan;
        }

        return $order->plan_id ? Plan::find($order->plan_id) : null;
    }

    private function determineScenario(Order $order, User $user, ?array $snapshot): string
    {
        if ($snapshot === null) {
            return $this->fallbackScenario($order);
        }

        if (!$snapshot['has_plan']) {
            return self::SCENARIO_NEW_PURCHASE;
        }

        if (!empty($snapshot['expired'])) {
            return self::SCENARIO_EXPIRED_REPURCHASE;
        }

        if ($snapshot['plan_id'] !== $user->plan_id) {
            return self::SCENARIO_PLAN_CHANGE;
        }

        return self::SCENARIO_RENEWAL;
    }

    private function fallbackScenario(Order $order): string
    {
        return match ((int) $order->type) {
            Order::TYPE_RENEWAL => self::SCENARIO_RENEWAL,
            Order::TYPE_UPGRADE => self::SCENARIO_PLAN_CHANGE,
            default => self::SCENARIO_NEW_PURCHASE,
        };
    }

    private function resolvePeriodMonths(Order $order): ?int
    {
        try {
            $periodKey = PlanService::getPeriodKey((string) $order->period);
        } catch (Throwable $e) {
            Log::warning('custom_traffic_reset.period_key_unresolved', [
                'order_id' => $order->id,
                'period' => $order->period,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return OrderService::STR_TO_TIME[$periodKey] ?? null;
    }

    private function calculateExpiredAt(string $scenario, int $months, ?array $snapshot): int
    {
        $timezone = config('app.timezone');
        $now = Carbon::now($timezone);

        if ($scenario === self::SCENARIO_RENEWAL && $snapshot && $snapshot['expired_at']) {
            $base = Carbon::createFromTimestamp($snapshot['expired_at'], $timezone);
            return $base->addMonths($months)->timestamp;
        }

        return $now->addMonths($months)->timestamp;
    }

    private function parseIntervalDays(?Plan $plan): ?int
    {
        if (!$plan || !$plan->tags) {
            return null;
        }

        $tags = is_array($plan->tags) ? $plan->tags : preg_split('/[,\s]+/', (string) $plan->tags);
        if (!$tags) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $tag = trim($tag);
            if (stripos($tag, 'interval_days:') !== 0) {
                continue;
            }

            $value = trim(substr($tag, strlen('interval_days:')));
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function calculateNextResetAtAfterOrder(
        string $scenario,
        int $intervalDays,
        ?array $snapshot,
        ?int $expiredAt
    ): ?int {
        $timezone = config('app.timezone');
        $now = Carbon::now($timezone);

        if ($scenario === self::SCENARIO_RENEWAL && $snapshot && !empty($snapshot['next_reset_at'])) {
            $previous = Carbon::createFromTimestamp($snapshot['next_reset_at'], $timezone);
            if ($previous->isFuture()) {
                $candidate = $previous;
            } else {
                $candidate = $now->copy()->addDays($intervalDays);
            }
        } else {
            $candidate = $now->copy()->addDays($intervalDays);
        }

        if ($expiredAt !== null && $candidate->timestamp > $expiredAt) {
            $candidate = Carbon::createFromTimestamp($expiredAt, $timezone);
        }

        return $candidate->timestamp;
    }

    private function calculateNextResetAfterTrafficReset(int $intervalDays, ?int $expiredAt): ?int
    {
        if ($intervalDays <= 0) {
            return null;
        }

        $timezone = config('app.timezone');
        $base = Carbon::now($timezone)->addDays($intervalDays);

        if ($expiredAt !== null && $base->timestamp > $expiredAt) {
            $base = Carbon::createFromTimestamp($expiredAt, $timezone);
        }

        return $base->timestamp;
    }

    private function calculateExpectedNextResetForSync(User $user, int $intervalDays, Carbon $now): ?int
    {
        if ($intervalDays <= 0) {
            return null;
        }

        $timezone = $now->getTimezone();
        $intervalSeconds = $intervalDays * 86400;

        $base = null;
        if ($user->last_reset_at) {
            $base = Carbon::createFromTimestamp($user->last_reset_at, $timezone);
        } elseif ($user->next_reset_at) {
            $base = Carbon::createFromTimestamp($user->next_reset_at, $timezone)->subSeconds($intervalSeconds);
        }

        if ($base === null) {
            $candidate = $now->copy()->addSeconds($intervalSeconds);
        } else {
            $candidate = $base->copy()->addSeconds($intervalSeconds);

            if ($candidate->timestamp <= $now->timestamp) {
                $delta = max(0, $now->timestamp - $base->timestamp);
                $steps = intdiv($delta, $intervalSeconds) + 1;
                $candidate = $base->copy()->addSeconds($steps * $intervalSeconds);
            }
        }

        if ($user->expired_at !== null && $candidate->timestamp > $user->expired_at) {
            return $user->expired_at;
        }

        return $candidate->timestamp;
    }
}
