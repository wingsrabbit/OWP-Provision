# 安装与上线手册 / Install Guide

> WHMCS 自动化 XC·GRE 公网 IP 交付插件。客户下单 → 自动 SSH 到接入交换机开通 IP 交付 → 暂停 / 恢复 / 销户反向拆除并回收资源。
>
> 🔴 **只动接入交换机**(vlan / port / Vlanif / Tunnel / LoopBack / ip route-static / qos / save)。**绝不**碰 BGP `network` / route-policy / AS / 上游路由器。交付段是已宣告聚合内的更具体段,**人工预置(null0 + 聚合宣告 + 上游放行)是前提**。
>
> 目标环境:WHMCS 9.x / PHP 8.3 / MariaDB。

---

## 0. 形态:一个包 = 两个同名模块 + 共用 lib

```
modules/
  servers/owp_provision/
    owp_provision.php         # server module:开通/暂停/恢复/销户 + 客户区 + Admin 按钮
    hooks.php                  # 下单交互:freeports/types/nodes AJAX + 购物车 JS(由 addon hooks 桥接加载)
    clientarea.tpl             # GRE 客户区模板(改对端)
    lib/
      Schema.php               # 建表 / 迁移(Capsule,幂等)
      Config.php               # 全局设置(tbladdonmodules)+ 每设备加密凭据(mod_owp_provision_config,key=dev{id}_*)
      Devices.php              # 多设备 CRUD + connConfig(id) 组装 + 默认设备判定 + 在用分配校验
      Resources.php            # 清单式 IPAM:逐条资源 CRUD + 实时占用 + 校验 + 母段切分 + 空闲条目查询
      Types.php                # 交付类型注册表(可配置 / 可扩展;enabled / frontend 开关)
      Ipam.php                 # 分配 / 回收(事务 + 行锁;从 Resources 清单挑空闲条目;按 device_id 隔离)
      Templates.php            # 渲染 XC(qos lr)/ GRE(只开通)/ 拆除 / 暂停 的 VRP 命令块
      Connection.php           # SSH 连接层(direct / jump)+ 自带 phpseclib v3(lib/sshv3/)
  addons/owp_provision/
    owp_provision.php         # addon module:设备(连接 + 凭据) / 清单式资源 / 占用总览 管理页
    hooks.php                  # 桥接:require server 模块的 hooks.php
install/schema.sql             # 等效建表 SQL(参考;正常由 addon activate 自动建)
```

> **hooks 加载**:WHMCS **不自动加载 server 模块的 `hooks.php`**,但**会自动加载已激活 addon 的 `hooks.php`**。故 `addons/.../hooks.php` 是桥接、`require` server 的 `hooks.php`(真正逻辑)。**改了 hooks 后 deactivate→activate 一次 addon 刷新 hook 缓存。**

- **两模块同名** `owp_provision`,共用同一套 `mod_owp_provision_*` 表。
- **lib 共享**:addon 通过 `require_once dirname(__DIR__,2).'/servers/owp_provision/lib/...'` 引用 server 模块下的 lib(单一副本,不复制)。因此 **server 模块必须安装**,addon 才能工作。
- **连接配置 = 多设备**:每台接入交换机一条记录存 `mod_owp_provision_devices`(非敏感)+ 每设备加密凭据存 `mod_owp_provision_config`(key=`dev{id}_*`)。在 addon 管理页「设备 / Devices」区增删改(**不在 Configure**)。`MetaData.RequiresServer=false`,**不需要建 WHMCS Server 条目**。
- **资源 / 分配按设备**:`mod_owp_provision_resources`(清单式,逐条具体资源)/ `_allocations` 都带 `device_id`;分配从「下单所选节点」对应设备的清单里挑一条空闲条目,连接也连该设备。

---

## 1. 上传文件

把两个模块目录上传到 WHMCS 安装目录(保持路径):

```
/path/to/whmcs/modules/servers/owp_provision/
/path/to/whmcs/modules/addons/owp_provision/
```

权限:目录 755、文件 644,owner 与站点一致(常见 `www:www`)。改 PHP 后 opcache 最长 60s 生效;要立即生效:**先清** `templates_c/*.php` **再** reload php-fpm。

> 模块自动探测 `phpseclib3\Net\SSH2`(优先用 `lib/sshv3/` 捆绑的 v3)或回退 WHMCS 自带的 v2。无需额外安装依赖。

---

## 2. 激活 addon(自动建表)

WHMCS 后台 → **Setup → Addon Modules** → 找到「IP Delivery」→ **Activate**。
激活调 `lib/Schema.php`,**幂等**建 6 张表:`mod_owp_provision_devices / _pools / _resources / _allocations / _config / _log`。

> 若激活报建表错误:手动跑 `install/schema.sql`(只跑其中的 `CREATE TABLE` 段)。

激活后点该 addon 的 **Configure**,授权可访问该 addon 的管理员角色。

---

## 3. 添加设备(addon 管理页 → 设备 / Devices)

连接配置**不在 Configure**,而在 **addon 管理页**(Addons 菜单点「IP Delivery」进入)的「**设备 / Devices**」区。每台接入交换机一条,可自由增删改启停。**真实密钥只填这里,不进代码 / 文档;聊天 / 截图打码。**

点「**＋ 新增设备 / Add Device**」展开表单,一处填**连接配置 + 凭据**,「保存即覆盖」(敏感字段掩码显示且预填,清空 = 清除):

**先选「连接方式 / Connection Mode」:**
- **direct**:WHMCS 用 phpseclib **直连交换机**。只填「设备组」+「写账号组」。
  ⚠ 若设备 SSH 白名单只放特定网段 → 公网 WHMCS 主机直连会被挡,请改用 **jump**。
- **jump**:WHMCS 先连一台能 SSH 到设备的**跳板**,在跳板上 `sshpass ssh` 登设备。需「跳板组」+「设备组」+「写账号组」。

| 组 | 字段 | direct | jump | 值(示例)/ 说明 | 敏感 |
|---|---|:--:|:--:|---|:--:|
| — | 名称 Name | ✓ | ✓ | 友好名,如 `Edge-A`(下单节点选项用它 / 含 `#id`) | |
| — | 启用 Enabled | ✓ | ✓ | 勾上才参与下单 / 分配 | |
| — | 连接方式 Mode | ✓ | ✓ | `direct` 或 `jump` | |
| 设备 | Device Host / Port | ✓ | ✓ | `192.0.2.20` / `22` | |
| 设备 | KEX | — | △ | jump 内层 ssh 用(以 `+` 追加旧算法);direct 自协商不填 | |
| 跳板 | Jump Host / Port / User | — | ✓ | `192.0.2.10` / `22` / `root` | |
| 跳板 | Jump Key Path(私钥路径) | — | △ | WHMCS 主机上绝对路径;chmod 600、www 可读 | |
| 写账号 | **Write User / Write Password** | ✓ | ✓ | 🔴 自动化下发用(最小权限账号) | 密码🔒 |
| 写账号 | Read User / Read Password | △ | △ | 只读核查 / TestConnection;留空用写账号 | 密码🔒 |
| 跳板 | Jump Password(跳板密码) | — | △ | 不用私钥而用密码登跳板时填(私钥优先) | 🔒 |
| 跳板 | Key Passphrase(私钥口令) | — | △ | 私钥有口令才填 | 🔒 |
| 跳板 | Jump Private Key(私钥内容) | — | △ | 直接粘贴 PEM 整段;仅在内存用、不落盘(比路径更推荐) | 🔒 |
| — | Timeout | ✓ | ✓ | `30` 秒 | |

(✓ 必填　△ 可选 / 视情况　— 该模式不用;🔒 = 加密存 `mod_owp_provision_config` 的 `dev{id}_*`)

保存后展开该设备可:**Test Connection**(写账号 → `display version`,忽略 dry-run 始终真连)/ **启停** / **删除**(**有在用分配会被拒绝**,需先把相关服务销户)。

> **多设备 vs 单设备**:启用 ≥2 台时,下单需选「节点」(见 §5.2 的 `node` Configurable Option);只启用 1 台时下单页自动隐藏节点、后端默认用它。

### 🔴 上线前置(人工,每台设备一次性)

1. **写账号**:设备上**新建最小权限自动化账号**(命令级仅放 `vlan` / `interface` / `ip route-static` / `traffic` / `qos` / `save`),密码填进该设备「写账号」。**不要**用高权限账号。
2. **网络可达**(按所选模式):
   - **jump**:WHMCS 主机 → 跳板 `:22` 通;跳板上装 `sshpass`;WHMCS 主机有跳板私钥(chmod 600、www 可读)或在「私钥内容」粘贴。
   - **direct**:WHMCS 主机 → 设备 `:port` 通,且**设备 SSH 白名单放行该 WHMCS 主机 IP**。
3. **上游 / BGP 预置**:该设备要交付的聚合段必须**已写 null0 + BGP 宣告 + 上游入向放行**。客户开通只在该接入交换机切更具体——**插件永不动上游路由器**。

---

## 4. 配置资源(addon 管理页 → Resources,清单式 IPAM)

进入 addon 管理页,在 **资源 / Resources** 区配置。每类资源展示**一条条具体资源**,**占用由分配实时计算**(无需手工排除),按「设备 → 类型」分区。展开目标设备,每个类型块(VLAN / PTP / Tunnel / Prefix / Port / Loopback / ACL)里有两种录入 + 逐条管理:

- **母段切分**:填母段 / 范围 +(PTP / Prefix / Loopback 选**切分掩码**)→ 系统切成具体条目批量入清单。VLAN / Tunnel / ACL 填整数范围(`120-130`);Port 填逗号分隔(`XG1, XG2`)。
- **手动逐条**:单条录入任意值 + 任意掩码(如单独加 `198.51.100.8/29`、单个端口),标 `manual`。
- **每条资源**:标 **空闲 / 占用·服务#X**(占用是链接,点击跳客户页);**空闲**可行内改 value/mask/启用、启停、删除;**占用中锁定**,不可改 / 停 / 删(先销户释放)。还可勾选**批量删除**(占用的自动跳过)。

**录入示例**(按你的真实可交付聚合 / 空闲端口填,以下为 RFC 文档示例段):

| 类型 | 母段切分示例 | 手动逐条示例 | 说明 |
|---|---|---|---|
| `vlan` | 范围 `1000-1100` | 单个 `1200` | VLAN 1 通用保留(不分配) |
| `ptp` | `100.64.0.0/24` + 掩码 `/30` | `100.64.0.8/30`、或 `/31` | our/peer:/31→base、base+1;其余→base+1、base+2 |
| `prefix` | `203.0.113.0/24` + 掩码 `/28` | `203.0.113.16/29` | **交付段须为上游已宣告、对外可达**;分配时挑**掩码 = 订单交付掩码**的空闲条目 |
| `port` | `XG1, XG2, XG3` | 单个 `XG10` | 端口名(支持厂商简写,会规范化);物理布线线下做 |
| `loopback` | `198.51.100.0/28` + 掩码 `/32` | `198.51.100.30/32` | GRE 源 /32 |
| `tunnel` | 范围 `1000-1999` | 单个 `2000` | Tunnel / LoopBack 编号;不建条目时回退默认 1000-1999 |
| `acl` | 范围 `3000-3999` | 单个 `3100` | 占位分配(隧道不限速,见 §7);不建条目时回退默认 3000-3999 |

> **保存即校验 + 强制执行**:每次保存(切分 / 手动 / 编辑)先校验**格式 / 重复 / 重叠**(交付段只校验接入合理性,**不校验上游是否已宣告**)。不通过 → 拦下提示;确需写入点 **「⚠ 强制」** 绕过(记活动日志留痕)。
> **占用自动**:开通服务 → 对应条目自动「占用」;销户 → 自动「空闲」回清单。**无 meta.exclude**。
> 端口简写(`XGE0/0/10`)会规范化成 `XGigabitEthernet0/0/10`。

---

## 5. 建产品 + Configurable Options + Custom Fields

### 5.1 产品

Setup → Products/Services → 新建 Product「**IP Delivery**」:
- Type = **Other**
- Module Settings → Module Name = **IP Delivery**(`owp_provision`)
- Auto setup = **收款后自动开通**(On Payment)。

### 5.2 Configurable Options(客户下单可选,计价)

新建一个 Configurable Option Group 关联本产品,含:
- `delivery_type`:dropdown。选项值用 `XC` / `GRE`(与类型 key 对应)。**建议只放「前端开放的类型」**;即使放了未开放的类型,下单页 JS 也会自动隐藏、后端再兜底拒绝(双保险)。
- `bandwidth`:dropdown,如 `100M` / `200M` / `500M` / `1G`(→ 限速 CIR;1M = 1024kbps)。**XC 用此值下端口 `qos lr`;隧道仅记录不限速。**
- `prefix_size`:dropdown,如 `/32` / `/30` / `/29` / `/28`(→ 分配多大块)。
- `node`(**多设备时必加**):dropdown,**子选项 = 各启用设备**。子选项名**首选含 `#设备id`**(如 `Edge-A #1`、`Core #2`)——最稳,因为 WHMCS 购物车可能给可见文本追加价格后缀,而 `#id` 正则不受影响。也支持纯数字 `1`、`1| Edge-A`、或与「设备」区**完全一致**的设备名。**只启用 1 台时可不建 `node`**。

> 模块按**选项名**读取(`delivery_type` / `bandwidth` / `prefix_size` / `node`)。

### 5.3 Custom Fields(按产品)

- **Remote Endpoint IP**(Text,**勾 Show on Order Form**)—— GRE 隧道对端,校验合法公网 IPv4。JS 按交付方式条件显隐:**GRE 显示且必填,XC 自动隐藏**。
- **XC Port**(Text,**勾 Show on Order Form**)—— XC 下单时由 JS 转成「空闲端口下拉」(AJAX 实时拉空闲口)让客户选;**GRE 时自动隐藏**。留空 = 系统自动分配。
- 回写字段(勾 **Admin Only**,开通后自动填,便于展示;权威仍在 allocations 表):**Allocated VLAN** / **PTP** / **Delivered Prefix** / **Tunnel/Loopback**。

> 回写字段名必须**完全一致**(含大小写),否则模块找不到字段会静默跳过(不报错)。客户区 GRE 详情由 `clientarea.tpl` 渲染,不依赖回写字段。

### 5.4 下单交互(类型过滤 + XC 端口自选 + 条件显隐)

由模块 hooks 提供(`addons/.../hooks.php` 桥接 `servers/.../hooks.php`;**改 hooks 后 deactivate→activate addon 刷新缓存**):
- **前端只放开放类型**:JS 拉 `clientarea.php?ipd_ajax=types`,隐藏非开放子选项。后端兜底:类型须 enabled;前端单还须 frontend 开放(**admin 后台手动 Create 不受 frontend 限制**)。
- **节点联动 + 单设备免选**:JS 拉 `clientarea.php?ipd_ajax=nodes`;只 1 台时隐藏 `node`。换节点 → 重拉该设备空闲端口。
- **条件显隐**:XC 显示端口下拉、隐藏 EP IP;GRE 隐藏端口、显示 EP IP(必填)。
- **XC 端口下拉(按设备)**:AJAX `clientarea.php?ipd_ajax=freeports&device=<节点>`(登录态、只读)。选中端口写回 `XC Port`,`CreateAccount` → `Ipam::pickFreePort` 事务内校验(在该设备清单内 + 未占 → 锁定;否则可读报错、不下发)。

### 5.5 交付类型开关(addon Configure)

addon → Configure(类型注册表见 `lib/Types.php`):
- **enabledTypes**:启用的交付类型(代码可用、可被 admin 手动开通)。
- **frontendTypes**:前端开放下单的类型。
- 将来加类型:`lib/Types.php` 加一项 + 配套 `Ipam::allocateX` / `Templates::xCreate/xTeardown`,核心分发不改。

---

## 6. 全流程测试清单

> 测试期保持 **addon 全局 Dry-Run 开** 或 **产品级 Dry Run 开**,逐级放开。

1. **dry-run 核对命令块**:下单(分别测 `XC` / `GRE`)→ 去 Utilities → Logs → Module Log 看生成的命令块,**逐条核对**;确认**没有**任何 BGP / network / route-policy / 上游路由器语句。
2. **测试值真开(关 dry-run)**:把清单缩到安全测试值(临时 1 个 VLAN、1 个落在已宣告聚合内的测试段)。下单 XC → 校验 `Vlanif` UP、路由命中、`qos lr` 生效、`save` 成功;下单 GRE(填可控对端)→ 校验 Tunnel UP + 路由。对同一服务点 Admin **Re-push Config** 应幂等不报错。
3. **GRE 改对端**:客户区改 Remote IP → 设备 `Tunnel<N>` 的 `destination` 已变、其余不变;`allocations.remote_ip` 更新;改频限制生效。
4. **暂停 / 恢复**:Suspend → 对应静态路由被撤、对外不可达;Unsuspend → 路由重下恢复。
5. **销户回收**:Terminate → 按逆序拆净(路由 → 接口 → VLAN/Tunnel/LoopBack)+ `save`;校验设备无残留、`allocations.status=terminated`、资源回池。
6. 全过后恢复正式清单范围,关 dry-run,开放真实下单。

> Admin 按钮(服务页右侧):**Test Connection** / **Re-push Config**(幂等重下)/ **Show Live Config**(display 回显写进 Module Log)/ **Verify**(接口 UP + 路由校验)。

---

## 7. 设计要点

- **XC 限速** = 物理口 `qos lr inbound/outbound cir`(一口一客户,不占用紧张的出向 ACL slice,可规模化)。
- **GRE 隧道不限速**:VRP 的 Tunnel 接口不支持 `qos` / `traffic-policy`;插件只开通隧道、带宽仅记录。如需隧道限速,建议在下挂软路由旁路实现;`acl` 资源仅占位。
- **PTP 默认 /30**(our = 网络地址 +1、peer = +2;/31 则 base / base+1)。如需其它掩码,在 IPAM 清单按对应掩码切分 / 录入即可。
- **Suspend = 撤静态路由**(可逆、不动接口骨架),非 `shutdown`。
- **Terminate 校验若检测到残留 → 不回池、返回错误**(避免「记录回池但设备有残留」)。
- **手动释放(addon 页)只改 IPAM 记录、不触设备**,仅供对账纠偏;真正拆除走服务页 Terminate。

---

## 8. 回滚 / 卸载

**卸载顺序**(先停用、后删文件、**默认不删表**):
1. 把所有 IP Delivery 服务先正常 **Terminate**(让设备配置被干净拆除、资源回池)。**别**直接删模块——否则设备残留无人清理。
2. Setup → Addon Modules → 本模块 → **Deactivate**(`_deactivate` 故意**不删表**,保留占用记录)。
3. 产品改用其它模块或删产品(确认无在用服务)。
4. 删文件 `modules/servers/owp_provision/` 与 `modules/addons/owp_provision/`。清 `templates_c/*.php` + reload php-fpm。
5. 如确需清库:手动 `DROP TABLE mod_owp_provision_log, mod_owp_provision_allocations, mod_owp_provision_config, mod_owp_provision_resources, mod_owp_provision_pools, mod_owp_provision_devices;`(**会删占用 / 资源 / 设备 / 凭据,谨慎**)。

**设备残留检查**(卸载 / 异常后人工核查接入交换机):
```
display vlan
display ip routing-table
display interface brief | include Vlanif|Tunnel|LoopBack
```
对残留项手工 `undo` 后 `save`。

---

## 附录 A：v2 — 服务器租赁 / 托管 + VPN + iDRAC

v2 在「纯 IP 交付」之外加了**服务器租赁/托管**形态：下单选/绑一台空闲服务器 → 在它的交换机端口发 IP → 经 RouterOS 给它的 IPMI 开 VPN → （可选）在 iDRAC 建最小权限客户子账号。开通是**全局锁串行 + 每步落 oplog + 失败逐步回滚**，后台「开通队列」面板可看「卡在哪一步」。

### A.1 加 RouterOS 设备
addon 管理页「设备」区新增一台，**设备类型 = ros**：
- 连接：`direct`（WHMCS 直连 ROS 公网）+ 设备 IP/端口、写账号（RouterOS 用户，如 `admin`）；私钥填「私钥内容」（RouterOS 用 key 登录）或写密码。
- ROS 站点字段：`ros_lan_if`（IPMI 侧接口）、`ros_wan_if`（公网接口）、VPN 本端地址（如 `10.0.0.254`）、`ros_ikev2_peer`（可选，留空=不开 IKEv2）、IPsec PSK。
- 「Test Connection」验证（`/system version`）。

### A.2 VPN 地址池
在「资源」区，为该 **ROS 设备** 加 `vpn_ip` 类资源（母段切 /32，如 `10.0.1.0/24` → 254 个客户地址）。每开一个服务器服务占一个。

### A.3 服务器库存
「服务器库存」区新增每台物理机：名称、**所在交换机 + 端口**（NIC 线缆到的口）、**IPMI 所在 ROS**、IPMI IP、IPMI 类型（idrac/ilo/generic）、**iDRAC 管理账号/密码**（建客户子账号用）、线路标签、规格。状态 free 才会被下单绑定。

### A.4 iDRAC 自动建号（可选，全局开关）
addon *Configure* 填：
- **mgmtSrcIp** = 本 WHMCS 主机公网 IP（开通时 ROS 临时 DNAT 只放行它去配 iDRAC）。**留空 = 不自动建 iDRAC 账号**（只发 IP + VPN，账号人工建）。
- **dnatPortBase**（默认 20000）= 临时 DNAT 公网端口基数（实际端口 = 基数 + serviceid）。
> 开通时：ROS 开临时 DNAT（公网:端口 → iDRAC:443，锁 mgmtSrcIp）→ WHMCS 经 Redfish 建客户子账号（角色 Operator）→ **撤 DNAT**。销户反向删号。iDRAC 步骤**非致命**（失败仅告警，不影响已成的网络+VPN）。

### A.5 产品（服务器形态）
建产品时模块 ConfigOption **`serviceModel` 选 `server`**；Configurable Options 建议：`bandwidth`、`prefix_size`、`line`（线路，1:1 匹配带 line 标签的 prefix 资源与服务器）、可选 `server`（指定具体服务器 id；不填=按线路自动挑空闲）。VPN 账号默认用 WHMCS 服务自带 `username/password`，客户在服务详情页**「查看一次」**。

### A.6 线路 ↔ IP 1:1
给 `prefix` 资源条目打 `line` 标签（如 `HKBGP` / `HKBGP-CN`），各对应一个已在上游按该 blend 宣告的聚合；下单选 `line` → 从该线路的 prefix 与服务器里分配。

> 真机命令（VRP / RouterOS / iDRAC Redfish）由运维在真实设备验收；命令串分别集中在 `lib/Templates.php` 与 `lib/Drivers/{Ros,Drac}Driver.php`，便于按设备型号微调。
