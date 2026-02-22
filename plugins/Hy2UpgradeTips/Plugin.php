<?php

namespace Plugin\Hy2UpgradeTips;

use App\Services\Plugin\AbstractPlugin;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 注册客户端订阅服务器过滤钩子 - 添加 Hy2 不支持升级提示
        $this->filter('client.subscribe.servers', function ($servers, $user, $request) {
            return $this->addHy2UpgradeTipsIfNeeded($servers, $user, $request);
        });
    }

    /**
     * 获取客户端信息（复用新版 ClientController 的逻辑）
     */
    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));

        $clientName = null;
        $clientVersion = null;
        $isVortexClient = stripos($flag, 'vortex') !== false || stripos($flag, 'req/v3') !== false;

        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $potentialName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);

            if (in_array($potentialName, app('protocols.flags'))) {
                $clientName = $potentialName;
            }
        }

        if (!$clientName) {
            $flags = collect(app('protocols.flags'))->sortByDesc(fn($f) => strlen($f))->values()->all();
            foreach ($flags as $name) {
                if (stripos($flag, $name) !== false) {
                    $clientName = $name;
                    if (!$clientVersion) {
                        $pattern = '/' . preg_quote($name, '/') . '[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i';
                        if (preg_match($pattern, $flag, $vMatches)) {
                            $clientVersion = preg_replace('/^v/', '', $vMatches[1]);
                        }
                    }
                    break;
                }
            }
        }

        if (!$clientVersion) {
            if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) {
                $clientVersion = $matches[1];
            }
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion,
            'isVortexClient' => $isVortexClient
        ];
    }

    /**
     * 如果检测到 Hy2 节点被过滤，添加升级提示
     */
    private function addHy2UpgradeTipsIfNeeded($servers, $user, $request)
    {
        if (empty($servers)) {
            return $servers;
        }

        // 检查是否启用升级提示
        $enableUpgradeTips = $this->getConfig('enable_upgrade_tips', true);
        if (!$enableUpgradeTips) {
            return $servers;
        }

        // 检测客户端是否支持 Hysteria2（通过系统信息推断）
        $supportHy2 = $this->detectHy2SupportFromSystem($request);

        // 应用 AES-128-GCM 过滤逻辑（从旧版本迁移）
        $serversBeforeFilter = $servers;
        $servers = $this->filterAes128GcmServers($servers, $supportHy2);

        // 获取原始可用服务器（过滤前）
        $originalServers = ServerService::getAvailableServers($user);

        // 检查是否有 Hysteria2 节点被过滤掉了
        $hasHysteria2InOriginal = collect($originalServers)->contains(function ($server) {
            return $server['type'] === 'hysteria' &&
                   ($server['protocol_settings']['version'] ?? 1) === 2;
        });

        // 如果原始服务器中有 Hy2 节点，但过滤后的服务器中没有，说明被过滤了
        $hasHysteria2InFiltered = collect($servers)->contains(function ($server) {
            return $server['type'] === 'hysteria' &&
                   ($server['protocol_settings']['version'] ?? 1) === 2;
        });

        // 检查是否为 Vortex 客户端（不显示提示）
        $userAgent = strtolower($request->header('User-Agent', ''));
        $isVortexClient = stripos($userAgent, 'vortex') !== false || stripos($userAgent, 'req/v3') !== false;

        // 检查调试模式
        $debugMode = $this->getConfig('debug_mode', false);

        // 新逻辑：不是 Vortex 客户端，就显示官网信息
        // 不支持 Hy2 的客户端，额外显示升级提示信息
        if (!$isVortexClient || $debugMode) {
            if ($debugMode) {
                Log::info('Hy2UpgradeTips: 调试模式已启用，为所有客户端添加升级提示', [
                    'user_agent' => $request->header('User-Agent', ''),
                    'support_hy2' => $supportHy2
                ]);
            } else {
                Log::info('Hy2UpgradeTips: 客户端信息', [
                    'user_agent' => $request->header('User-Agent', ''),
                    'support_hy2' => $supportHy2
                ]);
            }

            // 为所有非Vortex客户端添加官网信息
            $servers = $this->addWebsiteInfo($servers);

            // 如果不支持Hy2，额外添加升级提示
            if (!$supportHy2) {
                $servers = $this->addHy2UpgradeTips($servers);
            }
        }

        return $servers;
    }

    /**
     * 通过协议管理器检测客户端是否支持 Hysteria2
     */
    private function detectHy2SupportFromSystem($request)
    {
        $clientInfo = $this->getClientInfo($request);
        $flag = $clientInfo['flag'];

        // 获取协议管理器
        $protocolsManager = app('protocols.manager');

        // 尝试匹配协议类
        $protocolClassName = $protocolsManager->matchProtocolClassName($flag);

        if ($protocolClassName) {
            // 创建协议类实例以检查其支持的协议
            try {
                $reflection = new \ReflectionClass($protocolClassName);
                if ($reflection->isInstantiable()) {
                    $protocolInstance = $reflection->newInstanceWithoutConstructor();

                    // 检查协议类的 allowedProtocols 是否包含 hysteria
                    $allowedProtocols = property_exists($protocolInstance, 'allowedProtocols')
                        ? $protocolInstance->allowedProtocols
                        : [];

                    $supportHy2 = in_array(Server::TYPE_HYSTERIA, $allowedProtocols);

                    
                    return $supportHy2;
                }
            } catch (\Exception $e) {
                Log::error('Hy2UpgradeTips: 协议类实例化失败', [
                    'protocol_class' => $protocolClassName,
                    'error' => $e->getMessage()
                ]);
            }
        }

        
        return false;
    }

    /**
     * 过滤指定加密方式的 shadowsocks 节点
     * 支持 Hy2 的客户端不会收到指定加密方式的 shadowsocks 节点
     * 不支持 Hy2 的客户端会收到所有 shadowsocks 节点
     */
    private function filterAes128GcmServers($servers, $supportHy2)
    {
        // 检查是否启用 AES 过滤
        $enableAesFilter = $this->getConfig('enable_aes_filter', true);
        if (!$enableAesFilter) {
            return $servers;
        }

        // 获取要过滤的加密方式配置
        $filterCipherMethods = $this->getConfig('filter_cipher_methods', "aes-128-gcm");
        $filterCiphers = array_filter(array_map('trim', explode(",", $filterCipherMethods)));

        return collect($servers)->reject(function ($server) use ($supportHy2, $filterCiphers) {
            // 过滤掉支持hy2的客户端的指定加密方式的shadowsocks节点
            if ($supportHy2 && $server['type'] == 'shadowsocks') {
                $serverCipher = $server['cipher'] ?? $server['protocol_settings']['cipher'] ?? null;
                if ($serverCipher && in_array($serverCipher, $filterCiphers)) {
                    return true; // 过滤掉这个服务器
                }
            }
            return false;
        })->values()->all();
    }

    /**
     * 为所有非Vortex客户端添加官网信息
     */
    private function addWebsiteInfo($servers)
    {
        if (empty($servers)) {
            return $servers;
        }

        // 获取配置的官网信息（不设置默认值，让用户可以真正留空）
        $websiteInfo = $this->getConfig('website_info', '');

        // 如果配置为空，则不下发官网信息
        if (empty(trim($websiteInfo))) {
            return $servers;
        }

        // 将官网信息作为节点添加到服务器列表开头
        $websiteServer = [
            'type' => 'shadowsocks',
            'host' => '0.0.0.0',
            'port' => 0,
            'password' => Helper::guid(true),
            'method' => '',
            'name' => $websiteInfo,
            'protocol_settings' => [
                'cipher' => 'aes-256-gcm'
            ],
            'tags' => [],
        ];

        array_unshift($servers, $websiteServer);

        return $servers;
    }

    /**
     * 为不支持hy2的客户端添加升级提示信息
     */
    private function addHy2UpgradeTips($servers)
    {
        if (empty($servers)) {
            return $servers;
        }

        // 获取配置的提示信息（不包含官网信息，因为已经单独添加）
        $hy2UpgradeTips = $this->getConfig('hy2_upgrade_tips',
            "建议更换专属客户端\n下载地址看官网\n当前客户端节点数量不全\n是给linux、电视、路由器使用的\n有问题请联系客服"
        );

        // 将升级提示内容按行分割
        $tipLines = array_filter(array_map('trim', explode("\n", trim($hy2UpgradeTips))));

        // 创建基础虚拟节点模板
        $baseTipServer = [
            'type' => 'shadowsocks',
            'host' => '0.0.0.0',
            'port' => 0,
            'password' => Helper::guid(true),
            'method' => '',
            'protocol_settings' => [
                'cipher' => 'aes-256-gcm'
            ],
            'tags' => [],
        ];

        // 将每行提示作为一个节点添加到服务器列表开头（倒序添加以保持原文顺序）
        foreach (array_reverse($tipLines) as $line) {
            $tipServer = array_merge($baseTipServer, [
                'name' => $line,
            ]);
            array_unshift($servers, $tipServer);
        }

        return $servers;
    }
}