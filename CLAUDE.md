# XBoard 插件钩子系统完整指南

## 📋 钩子系统概述

XBoard 提供了强大的钩子系统，允许插件在系统关键节点扩展功能。钩子分为两种类型：

- **过滤钩子 (Filter Hooks)** - 用于修改数据
- **动作钩子 (Action Hooks)** - 用于执行操作

## 🎯 钩子类型说明

### 过滤钩子 (Filter)
过滤钩子允许插件修改传递给它们的数据。插件可以接收数据，修改后返回新值。

```php
$this->filter('hook_name', function ($data) {
    // 修改数据
    $data['new_field'] = 'value';
    return $data;
});
```

### 动作钩子 (Action)
动作钩子允许插件在特定事件发生时执行操作，但不修改数据。

```php
$this->listen('hook_name', function ($data) {
    // 执行操作
    $this->doSomething($data);
});
```

## 🔧 完整钩子列表

### 用户相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `user.register.before` | action | `Request` | 用户注册前执行 |
| `user.register.after` | action | `User` | 用户注册后执行 |
| `user.login.after` | action | `User` | 用户登录后执行 |
| `user.password.reset.after` | action | `User` | 用户密码重置后执行 |
| `user.subscribe.response` | filter | `User` | 调整用户订阅响应数据 |
| `user.knowledge.resource` | filter | `array, Request, Resource` | 调整用户知识库资源数据 |

### 订单相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `order.create.before` | action | `User, Plan, period, couponCode` | 订单创建前执行 |
| `order.create.after` | action | `Order` | 订单创建后执行 |
| `order.after_create` | action | `Order` | 订单创建后执行（旧版） |
| `order.open.before` | action | `Order` | 订单开启前执行 |
| `order.open.after` | action | `Order` | 订单开启后执行 |
| `order.cancel.before` | action | `Order` | 订单取消前执行 |
| `order.cancel.after` | action | `Order` | 订单取消后执行 |

### 支付相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `available_payment_methods` | filter | `array` | 获取可用支付方式列表 |
| `payment.notify.before` | action | `method, uuid, request` | 支付回调前执行 |
| `payment.notify.failed` | action | `method, uuid, request` | 支付回调验证失败执行 |
| `payment.notify.verified` | action | `array` | 支付回调验证成功执行 |
| `payment.notify.success` | action | `Order` | 支付成功后执行 |

### 流量相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `traffic.process.before` | filter | `server, protocol, data` | 流量处理前调整数据 |
| `traffic.before_process` | filter | `server, protocol, data` | 流量处理前调整数据（旧版） |
| `traffic.reset.after` | action | `User` | 流量重置后执行 |

### 工单相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `ticket.create.after` | action | `Ticket` | 工单创建后执行 |
| `ticket.reply.user.after` | action | `Ticket` | 用户回复工单后执行 |
| `ticket.reply.admin.after` | action | `Ticket, TicketMessage` | 管理员回复工单后执行 |
| `ticket.close.after` | action | `Ticket` | 工单关闭后执行 |

### 协议相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `protocol.servers.filtered` | filter | `array` | 调整协议服务器列表 |

### 订阅相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `subscribe.url` | filter | `string` | 调整订阅链接URL |
| `client.subscribe.before` | action | - | 客户端订阅前执行 |
| `client.subscribe.unavailable` | action | - | 客户端订阅不可用执行 |
| `client.subscribe.servers` | filter | `array, User, Request` | 调整客户端订阅服务器列表 |

### 服务器相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `server.users.get` | filter | `array, Node` | 获取服务器用户列表 |

### Telegram相关钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `telegram.message.before` | action | `array` | Telegram消息处理前执行 |
| `telegram.message.handle` | filter | `bool, array` | 处理Telegram消息（返回是否处理） |
| `telegram.message.unhandled` | action | `array` | Telegram消息未处理执行 |
| `telegram.message.after` | action | `array` | Telegram消息处理后执行 |
| `telegram.message.error` | action | `array, Exception` | Telegram消息处理错误执行 |
| `telegram.bot.commands` | filter | `array` | 获取Telegram机器人命令列表 |
| `user.telegram.bind.after` | action | `User` | 用户绑定Telegram后执行 |

### 前端配置钩子

| 钩子名称 | 类型 | 参数 | 描述 |
|---------|------|------|------|
| `guest_comm_config` | filter | `array` | 调整前端公共配置数据 |

## 🚀 钩子使用示例

### 过滤钩子使用

```php
// 在 Plugin.php 的 boot() 方法中
$this->filter('guest_comm_config', function ($config) {
    // 添加前端配置
    $config['my_plugin_enable'] = true;
    $config['my_plugin_setting'] = $this->getConfig('api_key', '');
    return $config;
});
```

### 动作钩子使用

```php
// 用户注册后执行操作
$this->listen('user.register.after', function ($user) {
    // 发送欢迎邮件
    $this->sendWelcomeEmail($user);

    // 记录日志
    \Log::info('New user registered', ['user_id' => $user->id]);
});
```

### 支付回调钩子

```php
// 支付成功后执行操作
$this->listen('payment.notify.success', function ($order) {
    // 更新用户状态
    $user = $order->user;
    $user->status = 'active';
    $user->save();

    // 发送通知
    $this->sendPaymentSuccessNotification($user, $order);
});
```

## 📝 最佳实践

### 1. 钩子注册位置

所有钩子应该在插件的 `boot()` 方法中注册：

```php
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 注册过滤钩子
        $this->filter('guest_comm_config', function ($config) {
            // ...
        });

        // 注册动作钩子
        $this->listen('user.register.after', function ($user) {
            // ...
        });
    }
}
```

### 2. 错误处理

在钩子回调中应该包含适当的错误处理：

```php
$this->listen('payment.notify.success', function ($order) {
    try {
        // 业务逻辑
        $this->processPayment($order);
    } catch (\Exception $e) {
        \Log::error('Payment processing failed', [
            'order_id' => $order->id,
            'error' => $e->getMessage()
        ]);
    }
});
```

### 3. 性能考虑

- 避免在钩子中执行耗时操作
- 对于耗时任务，使用队列处理
- 合理使用缓存减少重复计算

## 🔍 调试技巧

### 查看可用钩子

```bash
# 查看系统中所有可用的钩子
php artisan hook:list
```

### 钩子调试日志

```php
// 在钩子回调中添加调试日志
$this->listen('user.register.after', function ($user) {
    \Log::debug('User registered hook triggered', [
        'user_id' => $user->id,
        'email' => $user->email
    ]);

    // 业务逻辑...
});
```

## 📚 钩子开发建议

### 1. 保持钩子简洁

钩子回调应该专注于单一职责，避免过于复杂的逻辑。

### 2. 数据一致性

在过滤钩子中修改数据时，确保数据格式和类型的一致性。

### 3. 依赖注入

在钩子回调中可以通过依赖注入获取所需服务：

```php
$this->listen('user.register.after', function ($user) {
    $mailService = app(\App\Services\MailService::class);
    $mailService->sendWelcomeEmail($user);
});
```

## 🎯 常用钩子场景

### 用户注册流程

```php
// 用户注册前验证
$this->listen('user.register.before', function ($request) {
    // 自定义验证逻辑
    if (!$this->validateRegistration($request)) {
        throw new \Exception('Registration validation failed');
    }
});

// 用户注册后操作
$this->listen('user.register.after', function ($user) {
    // 发送欢迎邮件
    $this->sendWelcomeEmail($user);

    // 创建默认配置
    $this->createDefaultSettings($user);
});
```

### 支付流程扩展

```php
// 添加自定义支付方式
$this->filter('available_payment_methods', function ($methods) {
    $methods[] = [
        'name' => 'my_custom_payment',
        'label' => '自定义支付',
        'handler' => MyCustomPaymentHandler::class
    ];
    return $methods;
});

// 支付成功处理
$this->listen('payment.notify.success', function ($order) {
    // 更新业务状态
    $this->updateBusinessStatus($order);

    // 发送通知
    $this->sendPaymentSuccessNotifications($order);
});
```

## ⚡ 钩子系统优势

- **非侵入式扩展**：无需修改核心代码即可扩展功能
- **模块化设计**：每个插件独立，互不影响
- **灵活配置**：可根据需要启用或禁用特定钩子
- **性能优化**：钩子系统经过优化，对性能影响极小

通过合理使用钩子系统，开发者可以轻松扩展 XBoard 的功能，满足各种定制化需求。