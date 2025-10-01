# 流量耗尽自动重置插件（TrafficReset）

## 插件简介

该插件监控用户流量使用情况，当用户流量用尽时自动触发提前重置，缩短用户有效期并重置流量，实现"流量用尽即重置"的自动化管理。

## 核心功能

### 1. 流量耗尽检测
- 实时监控用户流量使用率（99%阈值）
- 仅对有套餐且未封禁的用户生效
- 支持自定义周期和系统默认周期的用户

### 2. 智能重置策略
- **按月结日**：将到期日提前至今日对应的结日
- **每月一号**：直接回拨一个月保持时分秒
- **自定义周期**：从原到期时间中扣除一个周期

### 3. 条件验证
- 检查用户剩余时间是否足够一个周期
- 验证套餐重置策略是否支持自动重置
- 确保重置操作不会导致用户立即过期

## 配置项说明

| 配置项 | 说明 | 默认值 | 类型 |
| --- | --- | --- | --- |
| `enable_auto_reset` | 启用自动重置 | `true` | boolean |
| `schedule_frequency` | 定时任务频率 | `hourly` | select |
| `batch_size` | 批处理用户数量 | `100` | number |
| `auto_reset_on_exceed_monthly` | 按月结日重置 | `true` | boolean |
| `auto_reset_on_exceed_first_day` | 每月一号重置 | `true` | boolean |
| `auto_reset_on_exceed_custom` | 自定义周期重置 | `true` | boolean |

**定时任务频率说明：**
- `minutely`：每分钟执行一次
- `hourly`：每小时执行一次
- `daily`：每天00:00执行一次

## 执行逻辑

### 用户筛选条件
```sql
SELECT * FROM users
WHERE transfer_enable > 0
  AND banned = 0
  AND (u + d) >= transfer_enable * 0.99
  AND plan_id IS NOT NULL
```

### 重置条件验证

#### 自定义周期用户
```php
// 检查剩余时间是否大于周期天数
$remainingDays = ($user->expired_at - time()) / 86400;
if ($remainingDays <= $intervalDays) {
    return false; // 跳过重置
}
```

#### 系统默认周期用户
```php
// 按月结日和每月一号都按30天计算
$requiredDays = 30;
$remainingDays = ($user->expired_at - time()) / 86400;
if ($remainingDays <= $requiredDays) {
    return false; // 跳过重置
}
```

### 重置时间计算

#### 按月结日重置
```php
$currentDay = (int) date('d');
$expireDay = (int) date('d', $currentExpiredAt);
$daysDiff = $expireDay - $currentDay;

$newExpiredAt = $daysDiff > 0
    ? strtotime("-$daysDiff days", $currentExpiredAt)
    : strtotime('-1 month', $currentExpiredAt);
```

#### 每月一号重置
```php
$newExpiredAt = strtotime('-1 month', $currentExpiredAt);
```

#### 自定义周期重置
```php
$newExpiredAt = strtotime("-{$intervalDays} days", $currentExpiredAt);
```

## 套餐标签支持

### 自定义周期识别
插件自动识别套餐标签中的 `interval_days:N` 配置：

```
interval_days:7     # 每周重置周期
interval_days:15    # 每半月重置周期
interval_days:30    # 每月重置周期
```

### 重置策略映射
```php
$mapping = [
    null => '跟随系统设置',
    Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => '每月 1 号',
    Plan::RESET_TRAFFIC_MONTHLY => '按月结日',
    Plan::RESET_TRAFFIC_NEVER => '不重置',
    Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => '每年 1 月 1 日',
    Plan::RESET_TRAFFIC_YEARLY => '按年结日',
];
```

## 技术特性

- **事务安全**：所有重置操作在数据库事务中执行
- **批量处理**：支持分批处理大量用户，避免数据库压力
- **条件验证**：严格的剩余时间验证，防止用户立即过期
- **错误恢复**：单个用户重置失败不影响其他用户
- **详细日志**：记录完整的重置过程和策略信息
- **兼容性**：与 CustomTrafficReset 插件完全兼容
- **性能优化**：使用合理的查询条件和批量处理机制

## 使用建议

1. **频率设置**：建议设置为每小时执行，平衡实时性和性能
2. **批量大小**：根据服务器性能调整批处理数量
3. **策略配置**：根据业务需求选择支持的重置策略
4. **监控日志**：定期检查重置日志，了解系统运行状况
5. **测试验证**：在生产环境启用前，先在测试环境验证重置逻辑
