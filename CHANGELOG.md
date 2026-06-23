# Changelog

本项目所有重要变更记录于此。
格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/),版本遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

## [2.5.0] - 2026-06-23

v2.4.0 后真机全链路干净开通（switch + VPN ✓），唯 iDRAC 仍失败——但已是 v2.3.0 改良过的真实错误 `HTTP 000`。规则对、问题在**时序**：iDRAC 临时 DNAT 规则刚下发对首个新连接尚未生效。无表/列变更，iDRAC 仍非致命。

### 修复
- **iDRAC 临时 DNAT 开通后首个 Redfish 调用时序竞争（HTTP 000）**：`dnatOpen()` 同步 exec 两条 NAT 规则后**同进程立刻**发 Redfish，而 src-nat(masquerade) 只对新连接首包(SYN)生效——规则刚落表对紧随的首个连接可能尚未生效 → 无 src-nat 建链、iDRAC 回包走自身网关回不来 → `HTTP 000`（手测在加规则与 curl 间有 ~2s 间隔即 200）。两手都做：
  - **settle 延时**：`RosDriver::dnatOpen` 下发规则后、返回前 `sleep` 可配 `dnatSettleDelay`（addon 全局配置，默认 2s，钳 1~10s）让规则对新连接生效。（已确认 `RosDriver::exec` 同步读回，规则确实落表。）
  - **Redfish 连接失败重试**：`DracDriver::redfish()` 拆出 `redfishOnce()`，在 `HTTP 000`（curl 连接失败/超时）时退避重试（1s,2s，共 3 次）；有任何 HTTP 响应（含 4xx/5xx）则立即返回不重试。重试都在 DNAT 通道开着的窗口内（dnatClose 仍在 finally）。

## [2.4.0] - 2026-06-23

v2.3.0 部署后对**已成功开通**的服务点 Create 重跑（补 iDRAC）时，`ros.vpn` 因 `/ppp profile` 撞名失败 → **整单回滚把在跑的交换机配置也拆了**。根因：`vpnRevoke` 漏删 profile（非幂等）。无表/列变更。

### 修复
- **`vpnRevoke` 漏删 `/ppp profile` 致重跑撞名、连锁拆服务（P0）**：`vpnGrant` 加 profile 时 `/ppp profile add name=<tag>` **不带 comment**，而 `vpnRevoke` 按 `comment` 删 → profile 残留为孤儿。任何 re-push / 重 Create / Terminate 后都留孤儿，下次 `vpnGrant` `add profile name=<tag>` 撞 `already exists` → `ros.vpn` 失败 → `create_server` 回滚（serverTeardown 拆交换机 vlan/Vlanif/qos + releaseByService）→ **把在跑的服务拆掉**。改为 `vpnRevoke` 按 **name** 删 profile（`/ppp profile remove [find name=<tag>]`，兼容现存无 comment 的孤儿），且置于 secret 删除之后（避免 profile 被 secret 引用而删不掉）。
- **重跑不复用已分配 `vpn_ip`（P1，泄漏 + 不一致）**：`ros.vpn` 每次 `pickFreeVpnIp` 取新地址，重跑会泄漏一个并与原 ROS profile 的 remote-address 不一致。新增 `Ipam::pickOrReuseVpnIp(rosId, serviceId)`：若该服务 allocation 已有 `vpn_ip` 且仍在本 ROS 的 vpn_ip 清单、未被其它在用服务占用，则**复用**；否则取新。幂等。

## [2.3.0] - 2026-06-23

v2.2.0 真机全链路真实下发成功（交换机 + ROS VPN 已落地），**唯独 iDRAC 自动建号失败**。修复经「临时 DNAT」访问 iDRAC Redfish 的两点不通（均真机实测确认，附 HTTP 状态证据）+ 误导错误。改动 ROS NAT 行为 + Redfish 请求头 → minor。无表/列变更。iDRAC 步骤仍非致命。

### 修复
- **临时 DNAT 缺 src-nat 致 iDRAC Redfish 不通（P1-a，HTTP 000）**：`RosDriver::dnatOpen` 原只加一条 `dstnat`，iDRAC 收到的源是 WHMCS 公网地址 → 回包走 iDRAC 自身默认网关（非 ROS）→ 永不回 WHMCS → curl `HTTP 000`。改为同时加一条 **srcnat masquerade**（`src=mgmtSrcIp dst=iDRAC dst-port=443 out=lan`，让 iDRAC 看到的源是 ROS 内网口、就近回包）；与 dstnat 共用 `owp-svc{id}:dnat` 注释，`dnatClose` 一并清除。真机实测：加 src-nat 后 iDRAC 由 000 变有响应。
- **Redfish Host 头为 DNAT 前端致 DELL iDRAC 回 HTTP 400（P1-b）**：`DracDriver` 经 `https://<ROS公网>:<pubPort>` 访问，curl 默认带 `Host: <ROS公网>` → 这台 DELL iDRAC 校验 Host、不符回 `HTTP 400 Base.1.8.GeneralError`。改为构造时传入 iDRAC 真实地址（`ipmiTarget`），`redfish()` 显式覆盖 `Host:` 头为真实地址（URL 仍走 DNAT 前端）。真机实测：`-H "Host: <iDRAC真实IP>"` → HTTP 200 + 正常 `ManagerAccountCollection`。
- **误导错误「iDRAC 无空闲账号槽位」（P2）**：`accounts()` 列举失败（非 2xx/超时）时返回空 → `firstEmptySlot()` 误判为「槽位满」。改为 `accounts()` 区分「列举失败」（记 `lastErr`：HTTP 码 + 脱敏短回显，000 标注连接失败）与「列举成功但无空槽」；`createUser` 在列举失败时报实际 HTTP 错误（提示查 DNAT/Host/凭据），不再误报槽位满。

## [2.2.0] - 2026-06-23

v2.1.1 真机首次**真实下发**（关 dry-run）暴露：VRP `save` 确认 `Y` 应答时序错导致所有真机开通失败回滚。修复改动了 VRP 传输的 save 交互（jump 改 `-tt` PTY + 分段喂 stdin、direct 改读 `[Y/N]` 再应答）并新增可配 `saveConfirmDelay`，故按 minor 发布。无表/列变更。

### 修复
- **VRP `save` 确认 `Y` 应答时序（P0，阻断所有真机开通）**：把含 `save` 的命令块一次性喂入设备时，`save\n` 的换行会被 `[Y/N]` 当空应答吃掉、随后同块里的 `Y` 变成独立命令报 `Unrecognized command` → save 永不确认 → `saveConfirmed()` 判失败 → 回滚（dry-run 全绿、真机此步必挂；XC/GRE/server 共用，全中招）。两条传输路径都修：
  - **jump**（`buildJumpExec`）：含 save 时改用 `-tt` 交互 PTY + **分段喂 stdin**——先送 config+save、**停顿** `saveConfirmDelay`（默认 2s、可配、钳 1~10s）等 `[Y/N]` 真正出现、再送 `Y` 应答、再 `quit` 退出；无 save 的只读/测试路径原样不变。
  - **direct**（`shellRun`/新增 `shellConfirmSave`）：遇 `save` 行**先阻塞读到 `[Y/N]` 提示再送 `Y`**（不再按通用「写一行→读提示符」把 save 与 Y 当两条普通行），落盘给足超时、未确认重试一次。
  - `saveConfirmed()` 命中串不变；保留 v1 已验证的命令内容/Templates/IPAM。红线：只动接入交换机、save 只落盘不改配置。
- **dry-run Terminate 漏释放服务器（P2）**：服务器产品在全局 dry-run 下 Terminate 时，dry-run 分支释放分配后提前 `return`，跳过了 `Servers::releaseByService()`（纯 DB、不触设备）→ 服务器仍 `rented` 需手动 free。改为 dry-run 也执行服务器回库存（幂等），并补 `ros.vpn`/`drac` 的 `dryrun` oplog，使 DB 终态与真实路径一致。

## [2.1.1] - 2026-06-23

### 修复
- **白标**：`Ipam::carveFreePrefix()` 文档注释里的切母段示例误用了真实交付段，改为 RFC 5737 文档专用段 `203.0.113.0/27`（与仓库其余示例一致）。纯注释，无行为变更。

## [2.1.0] - 2026-06-23

**服务器租赁交付模型修复**：v2.0.0 真机首测（服务器产品全局 dry-run）暴露的问题集合——核心是独立服务器错用了 IP Transit 的 PTP/路由模型。无表/列变更，均复用现有结构。

### 修复
- **独立服务器走「直连 VLAN + Vlanif 网关」模型，去 PTP/route-static（P0-1）**：服务器租赁/托管的独立服务器是接在交换机口的 **L2 终端主机**（不是路由对端），原误复用 `Ipam::allocateXc()` + `Templates::xcCreate()` 的 **PTP /30 + `ip route-static 客户段 → 对端`**（那是客户自带路由器的 IP Transit 模型）。新增 `Ipam::allocateServer()`（只挑 vlan + prefix 精确掩码 + 服务器固定 port，**不挑 ptp**，`delivery_type='server'`，拒绝 `/32`）+ `Templates::serverCreate/serverTeardown()`（vlan + Vlanif `ip address 交付段第一可用 IP` 当**网关** + 物理口 access + `qos lr`；**无 PTP、无 route-static**，交付段为 Vlanif 直连子网）+ `Templates::firstUsable()`（/≤30→网络+1、/31→RFC3021）。`VrpDriver::method()` 分派 server、校验只查 Vlanif/VLAN（**不查 routeHit**）。`ipd_create_server` 改用 `allocateServer`。红线不变：只动接入交换机 vlan/interface/Vlanif/qos/save。
- **自动生成并存回服务凭据，修「Other 类产品无 username 致 VPN/iDRAC 整段被跳过」（P0-2）**：WHMCS 对 Other 类产品常只生成 password、不生成 username → VPN 用户名为空 → `ros.vpn` 与 iDRAC 被跳过。新增 `ipd_ensure_service_credentials()`：username 空则生成确定性安全名 `svc<serviceid>`（`[a-z0-9]`，ppp/iDRAC 安全）；password 空则随机强密码；按需存回 `tblhosting`（password 走 WHMCS `EncryptPassword`）；**幂等**（已有不覆盖）。
- **dashboard.css 嵌入 WHMCS 后台修复（P1-2）**：原为整页独立仪表盘设计，注入 addon 页后 `.owp` 根 `min-height:100vh` 顶出约 836px 空白、`.owp-panel{display:none}` 靠 tab 状态选择器激活导致队列面板永久隐藏。修：`.owp` 去 `min-height:100vh` + 背景 transparent + padding 收为嵌入态；队列面板加 `id="owp-p-queue"` 命中既有激活规则。全部选择器本就 scope 在 `.owp`/`.owp-*`（无全局泄漏）。

### 变更
- **全局 helper 改唯一前缀 `owpprov_`，避免与历史插件 `ipd_*` 全局符号撞名（P1-1）**：旧 `termrat_ipdelivery` 未删时两插件 addon 主文件都定义全局 `ipd_*` → `Cannot redeclare ipd_admin_pool_kinds()` 整页 500。**62 个**全局 helper `ipd_*` → `owpprov_*`（按词边界精确改名）；WHMCS 模块入口 `owp_provision_*`（18 个）与命名空间类 `OwpProvision\*` 不变；会话/表单/GET/POST/DOM 协议字符串（`ipd_csrf_ca` / `ipd_token` / `ipd_action` / `ipd_ajax` / `ipd_remote_ip` / `ipd_xc_port_select`）保留不动。
- **清单式 IPAM 支持按需切母段（P2）**：`Ipam::pickFreePrefix()` 原只精确命中 `mask==请求掩码` 的空闲条目（客户自由选 /28-/32 需逐档无重叠预切）。改为先精确命中、无则 `carveFreePrefix()` 从「更大的空闲母段」**buddy 拆分**切出（删母段、插「切出段 + 各级 buddy 兄弟段」落库；占用按 CIDR 重叠判定 → 只切出段被占用、兄弟段保持空闲、可继续切/分配、Terminate 后可回收）；母段取自 freeItems（必不与分配重叠）→ 拆分安全，全程在 allocate 事务内原子执行，`source='carve'` 留痕。向后兼容（有精确预切时不触发）。

## [2.0.0] - 2026-06-22

**产品驱动重构**：从「IP 交付插件」重构为**产品驱动的开通编排器**——一个 WHMCS 产品 = 一份蓝图，编排器（全局锁串行 + 按步日志 + 失败逐步回滚）跨「华为 VRP 交换机 / MikroTik RouterOS / 服务器 iDRAC」编排开通。新增：服务器租赁·托管（绑机+发IP+IPMI VPN+iDRAC 建号）、多协议 VPN、服务器库存、后台开通队列时间线 UI。模块更名 `owp_provision`（表前缀 `mod_owp_provision_`）。详见下方各项。

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
