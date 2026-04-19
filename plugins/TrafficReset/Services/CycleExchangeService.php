<?php

namespace Plugin\TrafficReset\Services;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CycleExchangeService
{
    public const METHOD_MONTHLY = 'monthly';
    public const METHOD_FIRST_DAY_MONTH = 'first_day_month';
    public const METHOD_YEARLY = 'yearly';
    public const METHOD_FIRST_DAY_YEAR = 'first_day_year';

    public const SUPPORTED_METHODS = [
        self::METHOD_MONTHLY,
        self::METHOD_FIRST_DAY_MONTH,
        self::METHOD_YEARLY,
        self::METHOD_FIRST_DAY_YEAR,
    ];

    public const DEFAULT_METHODS = self::SUPPORTED_METHODS;

    public function __construct(
        private readonly TrafficResetService $trafficResetService
    ) {
    }

    public function exchangeUserCycle(User $candidate, array $config): bool
    {
        try {
            return (bool) DB::transaction(function () use ($candidate, $config) {
                $user = User::query()
                    ->with('plan')
                    ->lockForUpdate()
                    ->find($candidate->id);

                if (!$user instanceof User) {
                    return false;
                }

                $cycle = $this->resolveCycle($user->plan);
                if ($cycle === null || !$this->supportsMethod($cycle['method'], $config)) {
                    return false;
                }

                if (!$this->canExchange($user, $cycle, $config)) {
                    return false;
                }

                $originalExpiredAt = $user->expired_at;
                $newExpiredAt = $this->calculateDeductedExpiredAt($originalExpiredAt, $cycle);
                if ($newExpiredAt === null || $newExpiredAt <= time()) {
                    return false;
                }

                User::withoutEvents(function () use ($user, $newExpiredAt) {
                    $user->expired_at = $newExpiredAt;
                    $user->save();
                });

                if (!$this->trafficResetService->performReset($user, TrafficResetLog::SOURCE_AUTO)) {
                    throw new \RuntimeException('traffic reset failed');
                }

                $user->refresh();

                Log::info('traffic_reset.exchange_cycle.user_exchanged', [
                    'user_id' => $user->id,
                    'method' => $cycle['method'],
                    'threshold_percent' => (int) $config['usage_threshold_percent'],
                    'expired_at_before' => Carbon::createFromTimestamp($originalExpiredAt, config('app.timezone'))->toDateTimeString(),
                    'expired_at_after' => Carbon::createFromTimestamp($user->expired_at, config('app.timezone'))->toDateTimeString(),
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('traffic_reset.exchange_cycle.user_failed', [
                'user_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function resolveCycle(?Plan $plan): ?array
    {
        if (!$plan instanceof Plan) {
            return null;
        }

        $resetMethod = $plan->reset_traffic_method;
        if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }

        return match ($resetMethod) {
            Plan::RESET_TRAFFIC_MONTHLY => ['method' => self::METHOD_MONTHLY],
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => ['method' => self::METHOD_FIRST_DAY_MONTH],
            Plan::RESET_TRAFFIC_YEARLY => ['method' => self::METHOD_YEARLY],
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => ['method' => self::METHOD_FIRST_DAY_YEAR],
            default => null,
        };
    }

    public function canExchange(User $user, array $cycle, array $config): bool
    {
        if (!$this->isExchangeableUser($user, (float) $config['usage_threshold_percent'])) {
            return false;
        }

        $newExpiredAt = $this->calculateDeductedExpiredAt($user->expired_at, $cycle);

        return $newExpiredAt !== null && $newExpiredAt > time();
    }

    public function calculateDeductedExpiredAt(?int $expiredAt, array $cycle): ?int
    {
        if ($expiredAt === null) {
            return null;
        }

        $timezone = config('app.timezone');
        $anchor = Carbon::createFromTimestamp($expiredAt, $timezone);

        return match ($cycle['method'] ?? null) {
            self::METHOD_MONTHLY, self::METHOD_FIRST_DAY_MONTH => $anchor->copy()->subMonthNoOverflow()->timestamp,
            self::METHOD_YEARLY, self::METHOD_FIRST_DAY_YEAR => $anchor->copy()->subYearNoOverflow()->timestamp,
            default => null,
        };
    }

    private function supportsMethod(string $method, array $config): bool
    {
        $enabledMethods = $config['enabled_methods'] ?? self::DEFAULT_METHODS;

        return in_array($method, $enabledMethods, true);
    }

    private function isExchangeableUser(User $user, float $thresholdPercent): bool
    {
        if (!$user->plan || $user->plan_id === null || $user->banned || $user->transfer_enable <= 0) {
            return false;
        }

        if ($user->expired_at === null || $user->expired_at <= time()) {
            return false;
        }

        return $user->getTrafficUsagePercentage() >= $thresholdPercent;
    }
}
