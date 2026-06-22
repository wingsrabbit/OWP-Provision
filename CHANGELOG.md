# Changelog

本项目所有重要变更记录于此。
格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/),版本遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

v2 产品驱动重构进行中（分阶段 PR 合入）。

### 新增
- **CI**：GitHub Actions `lint` 工作流，每次 push/PR 用 PHP 8.3 对全部 `modules/**/*.php` 跑 `php -l` 语法校验（替代本地 lint）。
- **编排器 + 驱动层（P1）**：`Orchestrator`（全局锁**串行执行** + 按步日志 `oplog`（保留 7 天）+ **失败逐步回滚**）；`Drivers/DriverInterface` + `Drivers/VrpDriver`（把 v1 设备侧逻辑收进 VRP 驱动）。生命周期（开通/暂停/恢复/销户/改套餐/重下/改对端/各管理按钮）全改经编排器 + VrpDriver 执行、每步落 oplog。设备表加 `driver` 列（vrp|ros|drac）为多设备类型铺路。命令与流程不变（真机已验证）。
- **VPN 凭据一次性查看（P5）**：服务器服务的客户区显示 IPMI VPN 接入（用户名/分配地址/可达 IPMI/协议），密码「查看一次」——点开显示一次后标记 `vpn_revealed`、之后掩码（如忘联系客服重置）。凭据 = WHMCS 服务 username/password；密码加密存 allocations.vpn_pass_enc。
- **后台开通队列 + 步骤时间线 UI（P5）**：addon 顶部「开通队列 / Provisioning」面板——按服务读 `oplog`，每单一条可展开时间线（取锁→交换机→ROS→iDRAC→交付各步 + 状态/设备/时间/回显），失败步红色高亮、直接看「卡在哪一步」；点 svc 跳客户页。样式由 Claude Design 出图（`OWP-Provision Dashboard.dc.html`）经 DesignSync 拉取、移植为 `dashboard.css`（白标，scoped 在 `.owp`）。
- **iDRAC 自动建号（P4）**：`Drivers/DracDriver`（Redfish over HTTPS/curl，自签不校验 TLS；iDRAC9 槽位 PATCH 建/删客户子账号，角色 `Operator`）。开通到服务器后：RosDriver 开**临时 DNAT**（`https://<ROS公网>:基数+svcid → iDRAC:443`，只放行 addon 配的 `mgmtSrcIp`）→ Redfish 建最小权限子账号（账号=服务 VPN 凭据）→ **撤 DNAT**（finally 必撤）；销户反向删号。iDRAC 步骤**非致命**（失败仅告警、不毁已成的网络+VPN），全程 oplog。服务器加 `ipmi_user` + 加密 `srv{id}_ipmi_pass`（Config::serverSecret）；addon 全局加 `mgmtSrcIp`/`dnatPortBase`，服务器表单加 BMC 管理账号/密码。
- **服务器租赁/托管蓝图（P3）**：新表 `mod_owp_provision_servers`（库存：所在交换机+端口、IPMI、所在 ROS、线路、规格、状态）+ `lib/Servers.php`（CRUD + 原子绑定/释放，防并发双租）+ 后台「服务器库存」CRUD 面板。产品加 ConfigOption `serviceModel`（ip_transit/server）；`server` 时走 `ipd_create_server` 蓝图：**全局锁内** 绑空闲服务器 → 在其固定端口发 IP（复用 XC：vlan/vlanif/qos lr/route）→ 经其 ROS 开 IPMI VPN（`vpn_ip` 分配 + RosDriver::vpnGrant）→ 回写；任一步失败逐步回滚（撤 VPN/拆交换机/回收分配/释放服务器），每步落 oplog。Terminate 连带撤 VPN+管理 DNAT、释放服务器回库存。交付即「网络通 + VPN 可达 IPMI」，OS 客户自装。
- **RouterOS 驱动 + 多协议 VPN（P2）**：`Drivers/RosDriver`（私钥/密码 SSH + exec-per-command + 报错识别，自带传输不复用 VRP shell）。`vpnGrant/vpnRevoke`：一条 `/ppp secret`(service=any) 覆盖 **L2TP/PPTP/SSTP/OpenVPN** + 可选 **IKEv2**；每客户专属 profile（pin 固定 VPN /32）+ 隔离 filter（仅**公网 NAT** + **自身 IPMI**，其余 forward/input drop）；全部打 `owp-svc{id}` 注释幂等。`dnatOpen/dnatClose`：iDRAC 临时管理通道（锁 WHMCS 源）。设备表加 ROS 站点字段（`ros_lan_if/ros_wan_if/ros_l2tp_local/ros_ikev2_peer` + 加密 `ros_ipsec_psk`），后台设备表单加 driver 选择 + ROS 字段，Test Connection 按 driver 分发。新增 `vpn_ip` 资源类（清单式，/32）+ `Ipam::pickFreeVpnIp`；allocations 加 VPN 列（vpn_device_id/vpn_ip/vpn_target/vpn_user/vpn_pass_enc/vpn_revealed）。VPN 接入将由 P3 服务器租赁蓝图编排调用。

### 变更
- **模块更名** `owp_ipdelivery` → `owp_provision`（命名空间 `OwpProvision`、表前缀 `mod_owp_provision_`、品牌 DisplayName「OWP Provision」）。新前缀视为全新安装；旧 `owp_ipdelivery` 安装不在线迁移路径内。
- 本地工程并入单一 `OWP-Provision` 工作副本（内部部署记录走 `*.local.md` / `DEPLOY-*.md`，已 gitignore，不入公共库）。

### 路线图（后续 PR）
- 设备驱动分层 `Drivers/{Vrp,Ros,Drac}`（设备表加 `driver`）+ 编排器（全局锁串行、按步日志保留 7 天、失败逐步回滚）+ 蓝图（IP Transit XC / 服务器租赁·托管）。
- RouterOS 多协议 VPN（L2TP/PPTP/SSTP/OpenVPN 共用 ppp secret + IKEv2/PSK）+ 每客户隔离（仅公网 + 自身 IPMI）。
- 服务器库存表（型号/交换机+端口/IPMI/规格/状态）+ 下单 1:1 绑定 + iDRAC（Redfish，经 ROS 临时 DNAT）建最小权限账号。
- 凭据一次性查看；后台开通队列 + 步骤时间线 UI。

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
- **审计**:每次下发 `logModuleCall` + 可选 `mod_owp_provision_log`(命令块 / 设备回显 / 结果)。

[1.0.0]: https://github.com/wingsrabbit/OWP-IP-Deliver/releases/tag/v1.0.0
