# 邮件发送频率限制插件

## 功能介绍

基于 Redis 的邮件发送频率限制插件，用于控制后台批量发送邮件和系统自动提醒邮件的发送频率，防止邮件滥用或被标记为垃圾邮件。

## 受控邮件场景

| 场景 | 是否受限 |
|------|---------|
| 后台批量发送邮件 | ✅ 是 |
| 流量提醒邮件 | ✅ 是 |
| 过期提醒邮件 | ✅ 是 |
| 验证码邮件 | ❌ 否（独立保护） |
| 工单通知邮件 | ❌ 否（独立保护） |
| 邮件链接登录 | ❌ 否（独立保护） |

## 配置说明

### enable_rate_limit
- **类型**: boolean
- **默认值**: true
- **说明**: 是否启用邮件发送频率限制功能

### second_limit
- **类型**: number
- **默认值**: 1
- **说明**: 每秒最多发送邮件数量（0为不限制）

### minute_limit
- **类型**: number
- **默认值**: 30
- **说明**: 每分钟最多发送邮件数量（0为不限制）

### hour_limit
- **类型**: number
- **默认值**: 1500
- **说明**: 每小时最多发送邮件数量（0为不限制）

### daily_limit
- **类型**: number
- **默认值**: 30000
- **说明**: 每天最多发送邮件数量（0为不限制）

### limit_verify_codes
- **类型**: boolean
- **默认值**: false
- **说明**: 是否对验证码邮件也进行频率限制（默认不限制，因验证码有独立的60秒发送间隔保护）

### debug_logs
- **类型**: boolean
- **默认值**: false
- **说明**: 是否记录频率限制的详细调试信息

## 工作原理

1. 所有受控邮件通过 `MailService::dispatchEmail()` 统一调度
2. 调度时触发 `mail.send.before` 钩子
3. 插件在钩子中执行频率检查：
   - 秒级限制：基于 Hash 结构的原子计数
   - 分钟/小时/日级限制：基于 Sorted Set 的原子检查和记录
4. 超限时自动等待并重试

## Redis 键结构

- `mail_rate_limiter:second_atomic:{email}` - 秒级限流（Hash）
- `mail_rate_limiter:minute:global` - 分钟级限流（Sorted Set）
- `mail_rate_limiter:hour:global` - 小时级限流（Sorted Set）
- `mail_rate_limiter:daily:global` - 日级限流（Sorted Set）

## 版本历史

- 1.0.3 - 修复 Lua 脚本错误，实现原子化检查
- 1.0.0 - 初始版本
