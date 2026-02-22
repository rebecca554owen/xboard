<?php

namespace Plugin\Hy2UpgradeTips;

use App\Services\Plugin\AbstractPlugin;
use App\Services\ServerService;
use App\Utils\Helper;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * Hy2UpgradeTips Plugin - Hysteria2 升级提示插件
 *
 * 性能优化说明:
 * 1. 使用静态缓存 $cachedProtocolFlags 避免每次请求重复排序协议标志
 * 2. 移除重复的 ServerService::getAvailableServers 查询，直接使用钩子传入的 $servers
 * 3. 优化 Vortex 客户端检测顺序，提前返回避免不必要的协议检测
 * 4. 缓存协议匹配结果，减少反射操作开销
 * 5. 只在必要时（非 Vortex 客户端）才进行 Hy2 支持检测
 */
class Plugin extends AbstractPlugin
{
    protected static ?array $cachedProtocolFlags = null;

    public function boot(): void
    {
        $this->filter('client.subscribe.servers', function ($servers, $user, $request) {
            return $this->addHy2UpgradeTipsIfNeeded($servers, $request);
        });
    }

    private function addHy2UpgradeTipsIfNeeded($servers, $request)
    {
        if (empty($servers)) {
            return $servers;
        }

        if (!$this->getConfig('enable_upgrade_tips', true)) {
            return $servers;
        }

        $userAgent = strtolower($request->header('User-Agent', ''));
        $isVortexClient = stripos($userAgent, 'vortex') !== false || stripos($userAgent, 'req/v3') !== false;

        $debugMode = $this->getConfig('debug_mode', false);
        if ($isVortexClient && !$debugMode) {
            return $servers;
        }

        $supportHy2 = $this->detectHy2Support($request);

        if ($debugMode) {
            Log::info('Hy2UpgradeTips: 调试模式已启用', [
                'user_agent' => $request->header('User-Agent', ''),
                'support_hy2' => $supportHy2
            ]);
        }

        $servers = $this->filterAes128GcmServers($servers, $supportHy2);

        $servers = $this->addWebsiteInfo($servers);

        if (!$supportHy2) {
            $servers = $this->addHy2UpgradeTips($servers);
        }

        return $servers;
    }

    private function detectHy2Support($request): bool
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));

        $protocolClassName = $this->getCachedProtocolMatch($flag);
        if (!$protocolClassName) {
            return false;
        }

        $protocolInstance = $this->tryCreateProtocolInstance($protocolClassName);
        if (!$protocolInstance) {
            return false;
        }

        $allowedProtocols = property_exists($protocolInstance, 'allowedProtocols')
            ? $protocolInstance->allowedProtocols
            : [];

        return in_array(Server::TYPE_HYSTERIA, $allowedProtocols);
    }

    private function getCachedProtocolMatch(string $flag): ?string
    {
        if (self::$cachedProtocolFlags === null) {
            $protocolManager = app('protocols.manager');

            $reflection = new \ReflectionClass($protocolManager);
            $property = $reflection->getProperty('protocolFlags');
            $property->setAccessible(true);
            $protocolFlags = $property->getValue($protocolManager);

            if (!is_array($protocolFlags)) {
                self::$cachedProtocolFlags = [];
                return null;
            }

            $sorted = collect($protocolFlags)->sortByDesc(function ($protocols, $flag) {
                return strlen($flag);
            });

            self::$cachedProtocolFlags = $sorted->all();
        }

        foreach (self::$cachedProtocolFlags as $protocolFlag => $protocols) {
            if (stripos($flag, $protocolFlag) !== false) {
                return is_array($protocols) ? ($protocols[0] ?? null) : $protocols;
            }
        }

        return null;
    }

    private function tryCreateProtocolInstance(string $protocolClassName): ?object
    {
        try {
            $reflection = new \ReflectionClass($protocolClassName);
            if (!$reflection->isInstantiable()) {
                return null;
            }
            return $reflection->newInstanceWithoutConstructor();
        } catch (\Exception $e) {
            Log::error('Hy2UpgradeTips: 协议类实例化失败', [
                'protocol_class' => $protocolClassName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function filterAes128GcmServers($servers, bool $supportHy2): array
    {
        if (!$this->getConfig('enable_aes_filter', true)) {
            return $servers;
        }

        $filterCipherMethods = $this->getConfig('filter_cipher_methods', 'aes-128-gcm');
        $filterCiphers = array_filter(array_map('trim', explode(',', $filterCipherMethods)));

        if (empty($filterCiphers)) {
            return $servers;
        }

        return collect($servers)->reject(function ($server) use ($supportHy2, $filterCiphers) {
            if (!$supportHy2 || $server['type'] !== 'shadowsocks') {
                return false;
            }

            $serverCipher = $server['cipher'] ?? $server['protocol_settings']['cipher'] ?? null;
            return $serverCipher && in_array($serverCipher, $filterCiphers);
        })->values()->all();
    }

    private function addWebsiteInfo($servers): array
    {
        if (empty($servers)) {
            return $servers;
        }

        $websiteInfo = trim($this->getConfig('website_info', ''));
        if ($websiteInfo === '') {
            return $servers;
        }

        array_unshift($servers, [
            'type' => 'shadowsocks',
            'host' => '0.0.0.0',
            'port' => 0,
            'password' => Helper::guid(true),
            'method' => '',
            'name' => $websiteInfo,
            'protocol_settings' => ['cipher' => 'aes-256-gcm'],
            'tags' => [],
        ]);

        return $servers;
    }

    private function addHy2UpgradeTips($servers): array
    {
        if (empty($servers)) {
            return $servers;
        }

        $hy2UpgradeTips = $this->getConfig('hy2_upgrade_tips',
            "建议更换专属客户端\n下载地址看官网\n当前客户端节点数量不全\n是给linux、电视、路由器使用的\n有问题请联系客服"
        );

        $tipLines = array_filter(array_map('trim', explode("\n", trim($hy2UpgradeTips))));

        $baseTipServer = [
            'type' => 'shadowsocks',
            'host' => '0.0.0.0',
            'port' => 0,
            'password' => Helper::guid(true),
            'method' => '',
            'protocol_settings' => ['cipher' => 'aes-256-gcm'],
            'tags' => [],
        ];

        foreach (array_reverse($tipLines) as $line) {
            array_unshift($servers, array_merge($baseTipServer, ['name' => $line]));
        }

        return $servers;
    }
}