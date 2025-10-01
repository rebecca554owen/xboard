# Repository Guidelines

## 项目结构与模块划分
- `app/` 保存 Laravel 领域层（providers、jobs、protocols、services），其中 `Providers` 负责注册容器绑定，`Protocols` 对接代理协议；新增功能时优先沿用既有特性目录。
- `plugins/` 存放支付与消息插件，每个插件以 `Plugin.php` 为入口并附带 `config.json`，遵循共享 Contract 方便热插拔（更多细节见下方“插件架构与开发指南”）。
- `database/migrations` 与 `database/seeders` 管理结构升级和演示数据；确保迁移可回滚、Seeder 幂等，并将跨版本提示记录在 `docs/`。
- `resources/` 汇集前端入口 JS、Blade 视图、语言包与校验规则；用户主题位于 `theme/`（默认 `theme/Xboard`），静态资源发布在 `public/`。
- `.docker/` 以及根目录脚本 `init.sh`、`update.sh` 支持容器化部署与就地升级；`compose.sample.yaml` 提供默认服务编排样板。

## 插件架构与开发指南
插件统一放置于 `plugins/`，目录使用 StudlyCase，与 `config.json` 中的 snake_case `code` 互为映射。推荐骨架：

```
plugins/Example/
  Plugin.php
  config.json
  README.md
  Providers/PluginServiceProvider.php
  routes/web.php
  routes/api.php
  database/migrations/
  resources/views/
  resources/assets/
```

示例配置片段：

```json
{
  "name": "示例插件",
  "code": "sample_plugin",
  "version": "1.0.0",
  "type": "feature",
  "description": "扩展业务功能",
  "author": "XBoard Team",
  "require": {"xboard": ">=1.0.0"},
  "config": {
    "enable": {
      "type": "boolean",
      "default": false,
      "label": "启用插件",
      "description": "控制插件是否对外开放"
    }
  }
}
```

- **入口类**：`Plugin.php` 必须继承 `App\\Services\\Plugin\\AbstractPlugin`，构造器会自动写入插件代号与绝对路径；常用扩展点包括 `boot()` 注册钩子、`install()` 与 `cleanup()` 负责安装卸载、`update()` 处理版本迁移。
- **扩展方式**：事件侧重使用 `listen()` 捕获业务动作，数据加工选用 `filter()` 链式修改返回值；如需提前结束流程请调用 `intercept()` 返回响应，避免在插件内部直接 `abort()`。
- **配置载入**：`config.json` 内的 `config` 字段定义管理端表单，支持 `boolean`、`number`、`text`、`select` 等类型；安装时默认值会落库至 `v2_plugins.config`，启用后 `PluginManager` 自动注入，可在插件内结合 `Cache::forget("plugin_config_{$code}")` 或 `HasPluginConfig` Trait 处理热更新。
- **路由与视图**：可选创建 `routes/web.php` 或 `routes/api.php`，命名空间固定为 `Plugin\\<Studly>\\Controllers`；视图存放 `resources/views` 并由 `View::addNamespace` 注册，静态资源置于 `resources/assets` 后由管理器复制至 `public/plugins/<code>`。
- **数据库与调度**：迁移文件位于 `database/migrations`，`install()` 与 `update()` 会触发自动迁移；定时任务可覆盖 `schedule(Schedule $schedule)`，结合插件配置决定执行频率，避免在 `boot()` 中直接注册调度逻辑。
- **命令与服务提供者**：命令类放在 `Commands` 目录，`registerCommands()` 会按文件名自动注册；如需额外容器绑定或事件订阅，可实现 `Providers/PluginServiceProvider` 并在启用阶段由管理器加载。
- **安装与分发**：后台上传 `zip` 包时确保根目录包含 `config.json` 与 `Plugin.php`，版本遵循 SemVer，升级流程会先执行 `disable()`、迁移再调用插件 `update()`；`type` 可设为 `payment` 以纳入支付渠道列表，默认 `feature` 则作为业务功能插件。
- **测试与排障**：推荐在插件内记录关键操作日志并复用现有队列、缓存工具；开启 `dry_run` 等保护配置时优先使用 `Cache` 锁避免并发冲突，升级前执行 `php artisan plugin:update <code>` 验证流程。

## 构建、测试与开发命令
- 本地初始化依次执行 `composer install` 与 `php artisan xboard:install`，会生成 `.env`、迁移数据库并写入默认管理员。
- 日常升级使用 `php artisan migrate --seed`；在 CI 或生产批处理时追加 `--force` 并提前验证回滚方案。
- 开发调试建议 `php artisan octane:start --watch`，队列与定时任务通过 `php artisan horizon`、`php artisan schedule:work` 监控。
- 容器场景复制 `compose.sample.yaml` 为 `docker-compose.yaml`，再运行 `docker compose up -d`；需要重新安装时使用 `docker compose run web php artisan xboard:install`。
- Linux 主机可直接调用 `bash init.sh`、`bash update.sh` 复用 Composer 与 Artisan 自动化；Windows 推荐在 WSL 环境执行。

## 编码风格与命名规范
- 遵循 PSR-12，保持 4 空格缩进、LF 行尾（由 `.editorconfig` 约束），Markdown 允许必要的行尾双空格。
- 服务、作业、事件类采用 PascalCase；配置键、环境变量、数据库列保持 snake_case，Blade 组件使用 kebab-case。
- 优先沿用 `app/Support` 与 `App\\Traits` 中现有工具，并保持显式返回类型；提交前运行 `vendor/bin/phpstan analyse`（等级 5）。
- 若需格式化，可使用 `php-cs-fixer` 或 IDE 的 PSR-12 规则，避免在 PR 中混入纯格式化变更。

## 测试指引
- 后端测试位于 `tests/Unit`、`tests/Feature`，文件命名 `<Subject>Test.php`，命名空间镜像业务代码；可复用 `RefreshDatabase` Trait。
- 默认执行 `php artisan test --parallel`，长耗时任务可添加 `--without-tty`；需要稳定数据时使用工厂或主 Seeder。
- Stripe、Telegram、Btcpay 等外部依赖需 Mock，凭据放在 `.env.testing` 中的虚拟值，保持测试离线可复现。

## 提交与合并请求规范
- 采用 Conventional Commit（如 `0798b37 feat(telegram plugin): improve Telegram notification formatting`），scope 对应模块或插件名称。
- 每个 PR 聚焦单一变更，描述需包含摘要、关联 Issue、迁移说明及 UI 截图或日志（若涉及前端或展示层）。
- 在创建 PR 前确保 `php artisan test` 与 `vendor/bin/phpstan analyse` 通过，如修改 Docker 或 Octane 配置请在描述中标注后续操作。
- 基于 `master` rebase，避免提交生成文件（如 `theme/**/umi.js`、`public/storage`）与调试输出。

## 安全与配置提示
- `.env` 仅保留模板，敏感信息通过 `.env.example` 引导并定期轮换；生产部署务必开启 HTTPS 并使用强随机密钥。
- 确保 `storage/`、`.docker/.data` 拥有写权限，日志与缓存可用 `php artisan queue:flush`、`php artisan cache:clear` 清理。
- 调整后台路径或队列配置后记得执行 `php artisan octane:reload`，并同步更新监控或自动化脚本。
