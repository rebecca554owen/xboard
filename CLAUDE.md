# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

XBoard 是基于 Laravel 12 和 Octane 构建的现代化代理协议管理面板系统（支持 V2Ray、Shadowsocks、Trojan 等）。系统采用 React 管理后台、Vue3 用户前端，以及基于插件的可扩展架构。

## 技术栈

- **后端**: Laravel 12 + Octane (PHP 8.2+)
- **管理面板**: React + Shadcn UI + TailwindCSS
- **用户前端**: Vue3 + TypeScript + NaiveUI
- **数据库**: MySQL 5.7+
- **缓存/队列**: Redis + Octane Cache
- **部署**: Docker + Docker Compose

## 常用命令

### 初始化安装
```bash
# 本地安装
composer install
php artisan xboard:install

# Docker 安装
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    web php artisan xboard:install && \
docker compose up -d

# Linux 主机快速安装
bash init.sh
```

### 开发调试
```bash
# 启动 Octane 并监听文件变化
php artisan octane:start --watch

# 队列监控
php artisan horizon

# 定时任务监控
php artisan schedule:work

# 重载 Octane（修改配置后必须执行）
php artisan octane:reload
```

### 数据库与迁移
```bash
# 运行迁移和填充数据
php artisan migrate --seed

# 生产环境迁移（带 force 标志）
php artisan migrate --seed --force
```

### 测试
```bash
# 并行运行测试
php artisan test --parallel

# 运行静态分析
vendor/bin/phpstan analyse
```

### 维护更新
```bash
# 更新系统（需要 Git）
bash update.sh

# 或手动更新
git fetch --all && git reset --hard origin/master && git pull origin master
composer update
php artisan xboard:update

# 清理缓存
php artisan cache:clear
php artisan queue:flush

# 修改后台路径后重启
docker compose restart
```

## 架构设计

### 目录结构

- **`app/Providers/`** - 服务容器绑定和注册
- **`app/Protocols/`** - 代理协议实现（Clash、SingBox、Shadowrocket、Surge、V2Ray 等）
- **`app/Services/`** - 业务逻辑层（认证、支付、用户、插件管理等）
- **`app/Http/Controllers/V1/` & `V2/`** - API 版本化控制器
- **`app/Jobs/`** - 队列任务（异步处理）
- **`app/Models/`** - Eloquent ORM 模型
- **`plugins/`** - 热插拔插件（支付、Telegram、流量管理等）
- **`resources/`** - 前端入口、Blade 视图、语言文件
- **`theme/`** - 用户主题（默认：`theme/Xboard`）
- **`routes/`** - 路由定义（web.php、console.php、channels.php）
- **`database/migrations/`** - 数据库结构迁移
- **`.docker/`** - Docker 配置和部署文件

### 核心架构模式

**服务层模式**：业务逻辑封装在服务类中（`app/Services/`），控制器保持精简并委托给服务层处理。

**插件系统**：插件通过以下方式扩展功能：
- `Plugin.php` 主类继承 `AbstractPlugin`
- `config.json` 定义配置结构
- 通过 `HookManager` 实现事件驱动的钩子系统
- `routes/api.php` 或 `routes/web.php` 中定义路由
- `Commands/` 目录中的 Artisan 命令（自动注册）

**协议抽象层**：每个代理客户端协议（Clash、SingBox 等）在 `app/Protocols/` 中都有专门的类来生成特定客户端的配置。

**队列架构**：使用 Laravel Horizon 配合 Redis 处理后台任务（邮件、通知、统计处理等）。

**多前端设计**：独立的管理后台（React）和用户前端（Vue3），均消费同一套后端 API。

## 插件开发

插件遵循标准化结构：

```
plugins/YourPlugin/
├── Plugin.php           # 继承 AbstractPlugin，实现 boot() 和可选的 schedule()
├── config.json          # 配置结构定义（支持 string、number、boolean、json、yaml）
├── routes/api.php       # 插件 API 路由
├── Controllers/         # 控制器继承 PluginController 或使用 HasPluginConfig trait
└── Commands/            # Artisan 命令（插件启用时自动注册）
```

**插件核心概念**：
- 通过 `$this->getConfig('key', 'default')` 访问配置
- 在 `boot()` 中注册钩子：`$this->filter()` 用于修改数据，`$this->listen()` 用于执行操作
- 常用钩子：`user.register.after`、`payment.notify.verified`、`order.cancel.after`、`traffic.reset.after`
- 继承 `PluginController` 的控制器自动获得配置访问和状态检查能力
- 通过 `schedule(Schedule $schedule)` 方法注册定时任务
- `Commands/` 目录中的命令会自动注册

完整插件开发指南见 `docs/en/development/plugin-development-guide.md`。

## 代码规范

- 遵循 PSR-12 标准（4 空格缩进，LF 行尾，由 `.editorconfig` 强制）
- 服务、作业、事件类使用 PascalCase
- 配置键、环境变量、数据库列使用 snake_case
- Blade 组件使用 kebab-case
- 使用显式返回类型，符合 PHPStan level 5 规范
- 提交前运行 `vendor/bin/phpstan analyse`

## 重要注意事项

- **重启 Octane**：修改后台路径或队列配置后必须执行 `php artisan octane:reload` 或 `docker compose restart`
- **权限**：确保 `storage/` 和 `.docker/.data` 有写入权限（.docker/.data 需 chmod 777）
- **安全**：绝不提交 `.env` 文件；使用 `.env.example` 作为模板
- **迁移**：始终确保迁移可回滚，Seeder 幂等
- **测试**：使用 `.env.testing` Mock 外部依赖（Stripe、Telegram、BTCPay）
- **Git 工作流**：PR 基于 `master` 分支，使用 Conventional Commits 格式（如 `feat(plugin): description`）

## 插件开发详解

### 快速开始

#### 1. 创建配置文件 `config.json`

```json
{
    "name": "我的插件",
    "code": "my_plugin",
    "version": "1.0.0",
    "description": "插件功能描述",
    "author": "作者名称",
    "require": {
        "xboard": ">=1.0.0"
    },
    "config": {
        "api_key": {
            "type": "string",
            "default": "",
            "label": "API 密钥",
            "description": "API 密钥"
        }
    }
}
```

#### 2. 创建主插件类 `Plugin.php`

```php
<?php

namespace Plugin\YourPlugin;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 注册前端配置钩子
        $this->filter('guest_comm_config', function ($config) {
            $config['my_plugin_enable'] = true;
            $config['my_plugin_setting'] = $this->getConfig('api_key', '');
            return $config;
        });
    }
}
```

#### 3. 创建控制器

**推荐方式：继承 PluginController**

```php
<?php

namespace Plugin\YourPlugin\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;

class YourController extends PluginController
{
    public function handle(Request $request)
    {
        // 获取插件配置
        $apiKey = $this->getConfig('api_key');
        $timeout = $this->getConfig('timeout', 300);

        // 业务逻辑...

        return $this->success(['message' => 'Success']);
    }
}
```

#### 4. 创建路由 `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use Plugin\YourPlugin\Controllers\YourController;

Route::group([
    'prefix' => 'api/v1/your-plugin'
], function () {
    Route::post('/handle', [YourController::class, 'handle']);
});
```

### 钩子系统

#### 完整钩子列表

**用户相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `user.register.before` | action | Request | 用户注册前执行 |
| `user.register.after` | action | User | 用户注册后执行 |
| `user.login.after` | action | User | 用户登录后执行 |
| `user.password.reset.after` | action | User | 用户密码重置后执行 |
| `user.subscribe.response` | filter | User | 调整用户订阅响应数据 |
| `user.knowledge.resource` | filter | array, Request, Resource | 调整用户知识库资源数据 |

**订单相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `order.create.before` | action | User, Plan, period, couponCode | 订单创建前执行 |
| `order.create.after` | action | Order | 订单创建后执行 |
| `order.after_create` | action | Order | 订单创建后执行（旧版） |
| `order.open.before` | action | Order | 订单开启前执行 |
| `order.open.after` | action | Order | 订单开启后执行 |
| `order.cancel.before` | action | Order | 订单取消前执行 |
| `order.cancel.after` | action | Order | 订单取消后执行 |

**支付相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `available_payment_methods` | filter | array | 获取可用支付方式列表 |
| `payment.notify.before` | action | method, uuid, request | 支付回调前执行 |
| `payment.notify.failed` | action | method, uuid, request | 支付回调验证失败执行 |
| `payment.notify.verified` | action | array | 支付回调验证成功执行 |
| `payment.notify.success` | action | Order | 支付成功后执行 |

**流量相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `traffic.process.before` | filter | server, protocol, data | 流量处理前调整数据 |
| `traffic.before_process` | filter | server, protocol, data | 流量处理前调整数据（旧版） |
| `traffic.reset.after` | action | User | 流量重置后执行 |

**工单相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `ticket.create.after` | action | Ticket | 工单创建后执行 |
| `ticket.reply.user.after` | action | Ticket | 用户回复工单后执行 |
| `ticket.reply.admin.after` | action | Ticket, TicketMessage | 管理员回复工单后执行 |
| `ticket.close.after` | action | Ticket | 工单关闭后执行 |

**协议相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `protocol.servers.filtered` | filter | array | 调整协议服务器列表 |

**订阅相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `subscribe.url` | filter | string | 调整订阅链接URL |
| `client.subscribe.before` | action | - | 客户端订阅前执行 |
| `client.subscribe.unavailable` | action | - | 客户端订阅不可用执行 |
| `client.subscribe.servers` | filter | array, User, Request | 调整客户端订阅服务器列表 |

**服务器相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `server.users.get` | filter | array, Node | 获取服务器用户列表 |

**Telegram相关钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `telegram.message.before` | action | array | Telegram消息处理前执行 |
| `telegram.message.handle` | filter | bool, array | 处理Telegram消息（返回是否处理） |
| `telegram.message.unhandled` | action | array | Telegram消息未处理执行 |
| `telegram.message.after` | action | array | Telegram消息处理后执行 |
| `telegram.message.error` | action | array, Exception | Telegram消息处理错误执行 |
| `telegram.bot.commands` | filter | array | 获取Telegram机器人命令列表 |
| `user.telegram.bind.after` | action | User | 用户绑定Telegram后执行 |

**前端配置钩子**
| 钩子名称 | 类型 | 参数 | 描述 |
| ------------------------- | ------ | ----------------------- | ---------------- |
| `guest_comm_config` | filter | array | 调整前端公共配置数据 |

#### 钩子用法

**过滤钩子（修改数据）**
```php
$this->filter('guest_comm_config', function ($config) {
    $config['my_setting'] = $this->getConfig('setting');
    return $config;
});
```

**动作钩子（执行操作）**
```php
$this->listen('user.created', function ($user) {
    $this->doSomething($user);
});
```

### 定时任务

插件可通过实现 `schedule()` 方法注册定时任务：

```php
use Illuminate\Console\Scheduling\Schedule;

class Plugin extends AbstractPlugin
{
    public function schedule(Schedule $schedule): void
    {
        // 每小时执行
        $schedule->call(function () {
            \Log::info('Plugin scheduled task executed');
        })->hourly();
    }
}
```

### Artisan 命令

插件可自动注册 Artisan 命令，在 `Commands/` 目录中创建命令类：

```php
<?php

namespace Plugin\YourPlugin\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'your-plugin:test {action=ping} {--message=Hello}';
    protected $description = 'Test plugin functionality';

    public function handle(): int
    {
        $action = $this->argument('action');
        $message = $this->option('message');

        try {
            return match ($action) {
                'ping' => $this->ping($message),
                default => $this->showHelp()
            };
        } catch (\Exception $e) {
            $this->error('Operation failed: ' . $e->getMessage());
            return 1;
        }
    }
}
```

### 最佳实践

#### 简洁的主类
- 插件主类应该尽可能简洁
- 主要用于注册钩子和路由
- 复杂逻辑应放在控制器或服务中

#### 配置管理
- 在 `config.json` 中定义所有配置项
- 使用 `$this->getConfig()` 访问配置
- 为所有配置提供默认值

#### 错误处理
```php
public function handle(Request $request)
{
    // 检查插件状态
    if ($error = $this->beforePluginAction()) {
        return $error[1];
    }

    try {
        // 业务逻辑
        return $this->success($result);
    } catch (\Exception $e) {
        return $this->fail([500, $e->getMessage()]);
    }
}
```

### 配置类型支持

- `string` - 字符串
- `number` - 数字
- `boolean` - 布尔值
- `json` - 数组
- `yaml` - YAML 格式

完整插件开发指南详细内容请参考 `docs/en/development/plugin-development-guide.md`。