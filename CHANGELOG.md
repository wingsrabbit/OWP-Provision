# Changelog

本项目所有重要变更记录于此。
格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/),版本遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.0.0] - 2026-06-16

首个公开发布。

### 新增

- **XC 交付**:VLAN + Vlanif(/30 点对点互联)+ 端口 access + 静态路由 + 物理口 `qos lr` 按客户限速。
- **GRE 交付**:LoopBack 源 + Tunnel(`tunnel-protocol gre`)+ 静态路由(本设备不限速,带宽仅记录)。
- **全生命周期**:开通 / 暂停(撤路由)/ 恢复 / 销户(反向拆净)/ 改套餐;销户校验无残留后自动回收资源、回池。
- **清单式 IPAM**:VLAN / PTP / Prefix / Port / Loopback / Tunnel / ACL 逐条资源管理;占用由在用分配实时计算(无需手工排除);掩码自选;母段切分 + 手动逐条录入;保存即校验(格式 / 重复 / 重叠,可「强制」绕过并留痕);占用条目锁定保护;批量删除。
- **多设备**:支持多台接入交换机,下单选「节点」分配到对应设备并连接该设备;只启用一台时自动免选。
- **双连接模式**:`direct`(WHMCS 直连交换机)/ `jump`(经跳板 `sshpass` 登设备);每设备独立的加密凭据。
- **自带 phpseclib v3**:RSA 走 `rsa-sha2` 签名,兼容 OpenSSH ≥8.8(默认禁 `ssh-rsa`/SHA-1)的现代跳板;缺失时回退 WHMCS 自带 v2。
- **下单交互**:按「前端开放类型」过滤交付方式、XC 端口客户自选(AJAX 实时拉空闲口)、按交付方式条件显隐 `Remote Endpoint IP` / `XC Port` 字段。
- **客户自助**:GRE 服务客户区自助修改隧道对端 IP(触发重下发),带改频限制。
- **可扩展类型**:交付类型为注册表式(`lib/Types.php`),新增协议只需加一项 + 配套分配 / 模板,核心分发不变。
- **Dry-Run**:全局或产品级,只渲染命令块 + 记日志、不触设备,供上线前人工核对。
- **审计**:每次下发 `logModuleCall` + 可选 `mod_ipdelivery_log`(命令块 / 设备回显 / 结果)。

[1.0.0]: https://github.com/wingsrabbit/OWP-IP-Deliver/releases/tag/v1.0.0
