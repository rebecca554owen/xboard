# 自定义流量重置插件（CustomTrafficReset）

## 插件简介

该插件提供灵活的流量重置周期管理功能，通过套餐标签配置自定义重置间隔，支持与系统默认重置逻辑并行工作。

## 核心功能

### 1. 自定义重置周期
- 通过套餐标签 `interval_days:N` 配置重置间隔（N为天数）
- 支持与系统默认重置逻辑（月结日、每月一号等）共存
- 自动计算下次重置时间并更新 `next_reset_at` 字段

### 2. 智能事件触发
- **订单开通后**：自动计算重置时间和到期时间
- **流量重置后**：重新计算下次重置时间
- **套餐标签变更**：批量重新计算相关用户的重置时间

### 3. 到期时间计算
- 根据套餐标签中的基础周期和订单周期计算实际到期天数
- 支持季付、半年付、年付等多周期订单
- 自动更新 `expired_at` 字段

## 配置项说明

| 配置项 | 说明 | 默认值 | 类型 |
| --- | --- | --- | --- |
| `enabled` | 启用插件 | `true` | boolean |
| `default_interval_days` | 默认重置间隔（天） | `30` | number |
| `batch_size` | 批处理用户数量 | `100` | number |
| `enable_expired_at_calculation` | 启用到期日计算 | `true` | boolean |
| `check_interval_minutes` | 检查间隔（分钟） | `5` | number |

## 套餐标签配置

### 重置周期配置
在套餐标签中添加 `interval_days:N` 来配置自定义重置周期：

```
interval_days:7     # 每周重置
interval_days:15    # 每半月重置
interval_days:30    # 每月重置（默认）
interval_days:90    # 每季度重置
interval_days:0     # 不自动重置
```

### 到期周期配置
在套餐标签中添加 `expired_days:N` 来配置自定义到期周期：

```
expired_days:30     # 30天到期
expired_days:90     # 90天到期
expired_days:365    # 365天到期
```

## 执行逻辑

### 时间范围计算
```php
// 自定义周期：新周期从当前时间开始计算
$baseTime = $currentTime;

// 固定周期：优先使用上次重置时间作为基准
$baseTime = $user->last_reset_at ?: $currentTime;

// 计算下次重置时间
$nextResetAt = strtotime("+{$intervalDays} days", $baseTime);
```

### 订单周期乘数
```php
$multipliers = [
    'monthly' => 1,      // 月付
    'quarterly' => 3,    // 季付
    'half_yearly' => 6,  // 半年付
    'yearly' => 12,      // 年付
    'two_yearly' => 24,  // 两年付
    'three_yearly' => 36 // 三年付
];
```

### 重置时间验证
- 确保重置时间不超过用户到期时间
- 如果计算出的重置时间在过去，自动调整到未来
- 当 `interval_days <= 0` 时，设置 `next_reset_at` 为 null

## 定时任务

### 套餐标签变更检查
- 每5分钟检查一次套餐标签变更
- 自动重新计算相关用户的重置时间
- 使用缓存记录最后检查时间，避免重复处理

### 重置时间修复
- 检查并修复计算错误的 `next_reset_at` 时间
- 主要修复自定义周期用户被其他插件重置后的时间同步问题

## 事件钩子

### 注册的钩子
- `order.open.after`：订单开通后计算重置时间和到期时间
- `traffic.reset.after`：流量重置后重新计算下次重置时间

## 技术特性

- **事务安全**：所有数据库操作都在事务中执行
- **批量处理**：支持分批处理大量用户，避免数据库压力
- **错误恢复**：单个用户计算失败不影响其他用户
- **详细日志**：提供完整的执行过程记录
- **缓存优化**：使用缓存锁防止任务重复执行
- **兼容性**：与系统默认重置逻辑完全兼容