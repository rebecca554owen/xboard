# CustomTrafficReset 插件

自定义流量重置周期插件，通过套餐标签 `interval_days` 精确控制用户的下一次流量重置时间，并兼容 XBoard 现有的订单开通逻辑。

## 功能要点

- **按标签驱动的重置周期**：只有带 `interval_days:<天数>` 标签的套餐会启用自定义重置时间，无标签维持系统默认行为。
- **尊重订单场景**：覆盖无套餐新购、到期后续费、在期续费、换套餐四种典型场景，自动选择合适的起算时间。
- **保持到期逻辑不变**：`expired_at` 仍按照周期（按月）延长，与核心的 `OrderService` 保持一致。
- **流量重置同步**：当系统执行流量重置 (`traffic.reset.after`) 时，为带标签的用户重新计算 `next_reset_at`，确保周期连续。
- **定时巡检修正**：可通过配置指定巡检间隔，按最新标签及重置记录自动纠正 `next_reset_at`。
- **详细日志**：订单开通前后都会写入结构化日志，便于排查实际执行路径及计算结果。

## 标签定义

在套餐标签中添加以下格式即可启用自定义周期：

```
interval_days:30
interval_days:90
interval_days:7
```

标签值需为正整数，表示间隔天数。常见示例：

- `interval_days:7`：每 7 天重置一次流量
- `interval_days:15`：半月重置
- `interval_days:90`：季度重置

## 订单场景和预期行为

| 场景 | 判断依据 | 到期时间 (`expired_at`) | 下一次重置 (`next_reset_at`) |
|------|----------|-------------------------|-------------------------------|
| 无套餐新购 | 用户原本无套餐 | 当前时间起按订阅周期加月 | 当前时间 + `interval_days` |
| 到期后续费 | 用户套餐已过期 | 当前时间起按订阅周期加月 | 当前时间 + `interval_days` |
| 在期续费 | 套餐未到期且未换套餐 | 原到期时间再加订阅周期月数 | 若原 `next_reset_at` 仍在未来则保持不变；缺失或已过期时从当前时间 + `interval_days` 重新起算 |
| 换套餐 | 新旧套餐不同 | 当前时间起按新套餐订阅周期加月 | 当前时间 + `interval_days` |

> 续费时只调整有标签的套餐；对无标签套餐不会覆盖系统 `next_reset_at`。

## 钩子注册

插件在 `boot()` 中注册三个动作钩子，并在 `schedule()` 中依据配置安排定时任务：

- `order.open.before`：记录订单处理前的用户状态（套餐 ID、到期时间、下次重置时间）。
- `order.open.after`：依据场景重新计算 `expired_at` 与 `next_reset_at`，并保存变更。
- `traffic.reset.after`：在系统重置流量后为带标签用户计算新的 `next_reset_at`。
- 定时任务：当配置 `sync_interval_minutes` > 0 时，按设定间隔扫描带标签的用户，根据最新标签和重置记录修正下一次流量重置时间。

## 配置项

`config.json` 提供以下两个配置项：

```json
{
  "enabled": {
    "type": "boolean",
    "default": true,
    "label": "启用插件",
    "description": "关闭后保留原有流量重置逻辑，不再写入 next_reset_at。"
  },
  "sync_interval_minutes": {
    "type": "number",
    "default": 0,
    "label": "巡检间隔（分钟）",
    "description": "0 表示不自动巡检，可临时设置为 N 分钟以触发批量扫描与修正。"
  }
}
```

## 安装与使用

1. 将插件放置于 `plugins/CustomTrafficReset` 目录并在后台启用。
2. 给需要自定义周期的套餐添加 `interval_days:<天数>` 标签。
3. 按照正常流程创建订单或执行流量重置，即可触发插件逻辑。

## 排查建议

- 关注日志中 `custom_traffic_reset.*` 相关条目，快速定位订单场景及结果。
- 若 `next_reset_at` 未更新，确认套餐是否已设置正确标签，以及插件开关是否启用。*** End Patch*** End Patch**?** Wait format off? Need ensure apply_patch format proper: begin patch etc. Need newlines. Do again carefully.
