<?php

namespace Plugin\TrafficReset;

use App\Models\Plan;
use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\TrafficReset\Services\CycleExchangeService;

class Plugin extends AbstractPlugin
{
    private const VALID_SCHEDULE_FREQUENCIES = ['minutely', 'hourly', 'daily'];

    public function schedule(Schedule $schedule): void
    {
        $config = $this->getRuntimeConfig();
        if (!$config['enabled']) {
            return;
        }

        $task = $schedule->call(function () use ($config) {
            $this->scanUsers($config);
        });

        match ($config['schedule_frequency']) {
            'minutely' => $task->everyMinute(),
            'daily' => $task->dailyAt('00:00'),
            default => $task->hourly(),
        };

        $task->name('traffic_reset.exchange_cycle')->onOneServer();
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $this->migrateStoredConfig();
    }

    private function scanUsers(array $config): void
    {
        $service = app(CycleExchangeService::class);
        $processed = 0;
        $exchanged = 0;
        $thresholdFactor = $config['usage_threshold_percent'] / 100;
        $now = time();

        try {
            User::query()
                ->where('transfer_enable', '>', 0)
                ->where('banned', 0)
                ->whereNotNull('expired_at')
                ->where('expired_at', '>', $now)
                ->whereNotNull('plan_id')
                ->whereRaw('(u + d) >= transfer_enable * ?', [$thresholdFactor])
                ->with('plan:id,reset_traffic_method')
                ->orderBy('id')
                ->chunkById($config['batch_size'], function ($users) use (&$processed, &$exchanged, $service, $config) {
                    foreach ($users as $user) {
                        $processed++;
                        if ($service->exchangeUserCycle($user, $config)) {
                            $exchanged++;
                        }
                    }
                });

            if ($exchanged > 0) {
                Log::info('traffic_reset.exchange_cycle.completed', [
                    'processed' => $processed,
                    'exchanged' => $exchanged,
                    'threshold_percent' => $config['usage_threshold_percent'],
                    'enabled_methods' => $config['enabled_methods'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('traffic_reset.exchange_cycle.failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getRuntimeConfig(): array
    {
        return $this->normalizeConfig($this->config);
    }

    private function normalizeConfig(array $config): array
    {
        return [
            'enabled' => (bool) ($config['enabled'] ?? $config['enable_auto_reset'] ?? true),
            'schedule_frequency' => $this->normalizeScheduleFrequency($config['schedule_frequency'] ?? 'hourly'),
            'batch_size' => $this->clamp((int) ($config['batch_size'] ?? 100), 1, 10000),
            'usage_threshold_percent' => $this->clamp((int) ($config['usage_threshold_percent'] ?? 99), 1, 100),
            'enabled_methods' => $this->resolveEnabledMethods($config),
        ];
    }

    private function resolveEnabledMethods(array $config): array
    {
        if (
            array_key_exists('enable_monthly', $config)
            || array_key_exists('enable_first_day_month', $config)
            || array_key_exists('enable_yearly', $config)
            || array_key_exists('enable_first_day_year', $config)
        ) {
            return $this->normalizeEnabledMethods(array_keys(array_filter([
                CycleExchangeService::METHOD_MONTHLY => (bool) ($config['enable_monthly'] ?? true),
                CycleExchangeService::METHOD_FIRST_DAY_MONTH => (bool) ($config['enable_first_day_month'] ?? true),
                CycleExchangeService::METHOD_YEARLY => (bool) ($config['enable_yearly'] ?? true),
                CycleExchangeService::METHOD_FIRST_DAY_YEAR => (bool) ($config['enable_first_day_year'] ?? true),
            ])));
        }

        return $this->normalizeEnabledMethods($config['enabled_methods'] ?? $this->mapLegacyEnabledMethods($config));
    }

    private function mapLegacyEnabledMethods(array $config): array
    {
        $methods = array_keys(array_filter([
            CycleExchangeService::METHOD_MONTHLY => (bool) ($config['auto_reset_on_exceed_monthly'] ?? true),
            CycleExchangeService::METHOD_FIRST_DAY_MONTH => (bool) ($config['auto_reset_on_exceed_first_day'] ?? true),
        ]));

        return match ((int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY)) {
            Plan::RESET_TRAFFIC_YEARLY => [...$methods, CycleExchangeService::METHOD_YEARLY],
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => [...$methods, CycleExchangeService::METHOD_FIRST_DAY_YEAR],
            default => $methods,
        };
    }

    private function normalizeEnabledMethods(mixed $enabledMethods): array
    {
        if (!is_array($enabledMethods)) {
            return CycleExchangeService::DEFAULT_METHODS;
        }

        $methods = array_values(array_unique(array_filter(array_map(function ($method) {
            return is_string($method) ? trim($method) : null;
        }, $enabledMethods), function ($method) {
            return in_array($method, CycleExchangeService::SUPPORTED_METHODS, true);
        })));

        return $methods === [] ? CycleExchangeService::DEFAULT_METHODS : $methods;
    }

    private function normalizeScheduleFrequency(mixed $scheduleFrequency): string
    {
        return in_array($scheduleFrequency, self::VALID_SCHEDULE_FREQUENCIES, true) ? $scheduleFrequency : 'hourly';
    }

    private function clamp(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function migrateStoredConfig(): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin || empty($plugin->config)) {
            return;
        }

        $decoded = json_decode($plugin->config, true);
        if (!is_array($decoded)) {
            return;
        }

        if (array_key_exists('enabled_methods', $decoded) && array_key_exists('enabled', $decoded)) {
            return;
        }

        $normalized = $this->normalizeConfig($decoded);

        PluginModel::query()
            ->where('id', $plugin->id)
            ->update([
                'config' => json_encode($this->serializeConfig($normalized), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    private function serializeConfig(array $normalized): array
    {
        $enabledMethods = $normalized['enabled_methods'] ?? [];

        return [
            'enabled' => $normalized['enabled'],
            'schedule_frequency' => $normalized['schedule_frequency'],
            'batch_size' => $normalized['batch_size'],
            'usage_threshold_percent' => $normalized['usage_threshold_percent'],
            'enable_monthly' => in_array(CycleExchangeService::METHOD_MONTHLY, $enabledMethods, true),
            'enable_first_day_month' => in_array(CycleExchangeService::METHOD_FIRST_DAY_MONTH, $enabledMethods, true),
            'enable_yearly' => in_array(CycleExchangeService::METHOD_YEARLY, $enabledMethods, true),
            'enable_first_day_year' => in_array(CycleExchangeService::METHOD_FIRST_DAY_YEAR, $enabledMethods, true),
        ];
    }
}
