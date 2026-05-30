# 订阅安全控制插件

## 功能特性

### 🛡️ 核心安全功能
- **UA白名单检测** - 只允许常见代理客户端 User-Agent 获取订阅
- **IP频率限制** - 防止单IP高频访问
- **IP白名单** - 支持可信IP绕过检查
- **多种拦截响应** - 403/404/空内容响应

### 📊 监控与告警
- **详细日志** - 记录订阅安全事件
- **告警日志** - 达到阈值自动写入 critical 告警日志

## 配置说明

### UA白名单配置
默认只放行常见代理客户端：
- Clash / Sing-Box / Shadowrocket / Quantumult X
- v2rayN / v2rayNG / NekoBox / Hiddify 等

### 安全加固说明
- 默认不信任 `X-Forwarded-For` 等可伪造代理头；如站点位于可信 Nginx/Cloudflare 后方，可开启 `trust_proxy_headers`。
- 插件不会改写 Laravel 审计日志，只维护自身缓存计数和缓存索引。
- 插件通过 Xboard 插件配置页管理参数，不提供独立管理接口。

### IP限制配置
- `ip_limit_count`: 时间窗口内最大访问次数
- `ip_limit_window`: 时间窗口大小（秒）
- `ip_whitelist`: IP白名单，支持CIDR格式

### 响应配置
- `403`: 返回403禁止访问
- `404`: 返回404未找到
- `empty`: 返回空内容

## 安装使用

1. 将插件目录放置到 Xboard 的 `plugins/SubscribeSecurityControl/` 目录
2. 在 Xboard 管理后台安装并启用插件
3. 根据需要调整配置参数
4. 查看 Laravel 日志中的订阅安全事件

## Xboard 适配说明

- 插件目录名：`SubscribeSecurityControl`
- 插件 code：`subscribe_security_control`
- 插件通过 `client.subscribe.before` 钩子检查订阅请求。
- 配置项使用 Xboard 后台识别的 `label` 字段。
- 安装路径：`plugins/SubscribeSecurityControl/`，然后后台插件管理中安装/启用。
