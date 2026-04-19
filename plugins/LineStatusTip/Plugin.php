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

      if (!$user) {
        return;
      }

      $userService = new UserService();
      if ($userService->isAvailable($user)) {
        return;
      }

      $controller = app(\App\Http\Controllers\V1\Client\ClientController::class);

      $this->intercept($controller->doSubscribe($request, $user, [
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
      ]));
    });
  }
}
