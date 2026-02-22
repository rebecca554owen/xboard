# Hy2UpgradeTips 插件

为不支持 Hysteria2 协议的客户端显示升级提示，引导用户使用支持 Hysteria2 的客户端。

## 功能

- 自动检测客户端对 Hysteria2 的支持情况
- 过滤 AES-128-GCM 加密的 Shadowsocks 节点（优化服务器列表）
- 为不支持 Hysteria2 的客户端显示升级建议

## 服务器过滤逻辑

### 支持 Hysteria2 的客户端
- **不会收到** Hysteria2 节点（系统自动过滤）
- **不会收到** AES-128-GCM 加密的 Shadowsocks 节点

### 不支持 Hysteria2 的客户端
- **不会收到** Hysteria2 节点（系统自动过滤）
- **会收到** AES-128-GCM 加密的 Shadowsocks 节点
- **显示** 升级提示信息

## 不支持 Hysteria2 客户端收到的内容

当客户端不支持 Hysteria2 且系统过滤了 Hysteria2 节点时，会收到以下升级提示：

```
官网：{website_url}
建议更换专属客户端
下载地址看官网
当前客户端节点数量不全
是给linux，电视
还有路由器使用的
有问题请联系客服
```

其中 `{website_url}` 会自动替换为系统配置的官网地址。

## Vortex 客户端

Vortex 客户端不会收到上述升级提示信息。