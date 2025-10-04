<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Services\StatisticalService;
use App\Utils\Helper;

class Top extends Day
{
    public $command = '/top';
    public $description = '用户流量排行';

    public function handle($message, $match = [])
    {
        if (!$this->ensureAuthorized($message)) {
            return;
        }

        $days = $this->resolveDays($message->args);
        $limit = 10;

        $timeRange = $this->getTimeRange($days);
        $statService = new StatisticalService();

        $userRank = $this->resolveUserTrafficRank($statService, $days, $limit, $timeRange);

        if ($userRank->isEmpty()) {
            $reportDays = $days === 0 ? '今日' : ($days === 1 ? '昨日' : "{$days}天内");
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}暂无用户流量数据", 'markdown');
            return;
        }

        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $report = [
            "📊 用户流量排行",
            "时段：{$periodLabel}",
            "══════════════════════════",
        ];

        foreach ($userRank as $index => $user) {
            $rank = $index + 1;
            $total = Helper::trafficConvert($user->total);
            $report[] = "{$rank}. `{$user->email}`：{$total}";
        }

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
    }

    protected function resolveUserTrafficRank(StatisticalService $statService, int $days, int $limit, array $timeRange)
    {
        if ($days === 0) {
            $statService->setStartAt($timeRange['startAt']);
            $statService->setEndAt($timeRange['endAt']);

            $stats = collect($statService->getStatUser());
            if ($stats->isEmpty()) {
                return collect();
            }

            $userIds = $stats->pluck('user_id')->unique()->filter()->values();
            $users = User::whereIn('id', $userIds)->get()->keyBy('id');

            return $stats->map(function ($stat) use ($users) {
                    $userId = (int) ($stat['user_id'] ?? 0);
                    if (!$userId || !isset($users[$userId])) {
                        return null;
                    }

                    $upload = (int) round($stat['u'] ?? 0);
                    $download = (int) round($stat['d'] ?? 0);

                    return (object) [
                        'user_id' => $userId,
                        'email' => (string) $users[$userId]->email,
                        'u' => $upload,
                        'd' => $download,
                        'total' => $upload + $download,
                    ];
                })
                ->filter()
                ->sortByDesc('total')
                ->take($limit)
                ->values();
        }

        $statService->setStartAt($timeRange['startAt']);
        $statService->setEndAt($timeRange['endAt']);

        return collect($statService->getRanking('user_consumption_rank', $limit));
    }
}
