<?php

namespace App\Plugins\Telegram\Commands;

use App\Services\ServerService;
use App\Services\StatisticalService;
use App\Utils\Helper;

class Tops extends Start
{
    public $command = '/tops';
    public $description = '服务器流量排行';

    public function handle($message, $match = [])
    {
        if (!$this->ensureAuthorized($message)) {
            return;
        }

        $days = $this->resolveDays($message->args);
        $limit = 10;

        $timeRange = $this->getTimeRange($days);
        $statService = new StatisticalService();
        $serverRank = $this->resolveServerTrafficRank($statService, $days, $limit, $timeRange);

        if ($serverRank->isEmpty()) {
            $reportDays = $days === 0 ? '今日' : ($days === 1 ? '昨日' : "{$days}天内");
            $this->telegramService->sendMessage($message->chat_id, "📊 {$reportDays}暂无服务器流量数据", 'markdown');
            return;
        }

        $periodLabel = $this->formatTimeRangeLabel($timeRange);
        $report = [
            "📊 服务器流量排行",
            "时段：{$periodLabel}",
            "══════════════════════════",
        ];

        foreach ($serverRank as $index => $server) {
            $rank = $index + 1;
            $totalFormatted = Helper::trafficConvert($server->total);

            $serverModel = ServerService::getServer($server->server_id, $server->server_type);
            $serverName = $serverModel ? $serverModel->name : '未知节点';

            $report[] = "{$rank}. `{$serverName}` ({$server->server_type}) 节点：{$totalFormatted}";
        }

        $this->telegramService->sendMessage($message->chat_id, implode("\n", $report), 'markdown');
    }

    protected function resolveServerTrafficRank(StatisticalService $statService, int $days, int $limit, array $timeRange)
    {
        if ($days === 0) {
            $statService->setStartAt($timeRange['startAt']);
            $statService->setEndAt($timeRange['endAt']);

            return collect($statService->getStatServer())
                ->map(function ($stat) {
                    $upload = (int) round($stat['u'] ?? 0);
                    $download = (int) round($stat['d'] ?? 0);

                    return (object) [
                        'server_id' => (int) ($stat['server_id'] ?? 0),
                        'server_type' => (string) ($stat['server_type'] ?? ''),
                        'u' => $upload,
                        'd' => $download,
                        'total' => $upload + $download,
                    ];
                })
                ->sortByDesc('total')
                ->take($limit)
                ->values();
        }

        $rawRank = $days === 1
            ? StatisticalService::getServerRank('yesterday')
            : StatisticalService::getServerRank($timeRange['startAt'], $timeRange['endAt']);

        return collect($rawRank)
            ->map(function ($stat) {
                $upload = (int) round($stat['u'] ?? 0);
                $download = (int) round($stat['d'] ?? 0);
                $total = (int) round($stat['total'] ?? ($upload + $download));

                return (object) [
                    'server_id' => (int) ($stat['server_id'] ?? 0),
                    'server_type' => (string) ($stat['server_type'] ?? ''),
                    'u' => $upload,
                    'd' => $download,
                    'total' => $total,
                ];
            })
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }
}
