<?php

namespace Plugin\LineStatusTip;

use App\Services\Plugin\AbstractPlugin;
use App\Services\UserService;
use App\Utils\Helper;

class Plugin extends AbstractPlugin
{
  public function boot(): void
  {
    $this->listen('client.subscribe.unavailable', function () {
      $request = request();
      $user = $request->user();
      $controller = app(\App\Http\Controllers\V1\Client\ClientController::class);
      $userService = new UserService();
      if ($userService->isAvailable($user)) {
        return;
      }
      $customServers = [
        [
          'name' => $this->getConfig('tip_line_name', '您的流量已用完或套餐已到期，请及时续费'),
          'type' => 'shadowsocks',
          'host' => '0.0.0.0',
          'port' => 0,
          'password' => Helper::guid(true),
          'method' => '',
          'protocol_settings' => [
            'cipher' => 'aes-256-gcm'
          ],
          'tags' => [],
        ]
      ];
      $this->intercept($controller->doSubscribe($request, $user, $customServers));
    });
  }
}
