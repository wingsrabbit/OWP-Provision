# OWP IP Delivery

> **WHMCS 自动化公网 IP 交付插件** —— 客户下单后自动 SSH 到接入交换机,以 **物理交叉连接(XC)** 或 **GRE 隧道** 交付一段公网 IPv4;暂停 / 恢复 / 销户时反向拆除并自动回收资源。面向华为 VRP 兼容交换机。

![version](https://img.shields.io/badge/version-1.0-blue)
![WHMCS](https://img.shields.io/badge/WHMCS-9.x-2a9fd6)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4)
![license](https://img.shields.io/badge/license-MIT-orange)

---

## 目录

- [功能特性](#功能特性)
- [工作原理](#工作原理)
- [环境要求](#环境要求)
- [安装](#安装)
- [配置概览](#配置概览)
- [清单式 IPAM](#清单式-ipam)
- [限速与隧道说明](#限速与隧道说明)
- [目录结构](#目录结构)
- [技术栈](#技术栈)
- [安全](#安全)
- [License](#license)

---

## 功能特性

| 类别 | 特性 |
|------|------|
| 交付方式 | **XC**(VLAN + Vlanif /30 互联 + 端口 access + 静态路由 + 端口 `qos lr` 限速)、**GRE**(LoopBack 源 + Tunnel + 静态路由) |
| 全生命周期 | 开通 / 暂停(撤路由) / 恢复 / 销户(反向拆净) / 改套餐;**销户自动回收资源、回池** |
| 清单式 IPAM | 每类资源(VLAN / PTP / Prefix / Port / Loopback / Tunnel / ACL)**逐条可见**,占用由分配**实时计算**、无需手工排除;掩码自选、母段切分 + 手动逐条、保存即校验(可强制)、批量删除 |
| 多设备 | 支持多台接入交换机,下单选「节点」分配到对应设备;只有一台时自动免选 |
| 连接方式 | **direct**(WHMCS 直连交换机) / **jump**(经跳板 `sshpass` 登设备);每设备独立加密凭据 |
| 下单交互 | 类型过滤(只放开放类型)、**XC 端口客户自选**(AJAX 实时拉空闲口)、按交付方式条件显隐字段 |
| 客户自助 | GRE 客户区自助修改隧道对端 IP(触发重下发),带改频限制 |
| 现代跳板 | 自带 phpseclib v3,RSA 走 `rsa-sha2`,可过 OpenSSH ≥8.8(默认禁 `ssh-rsa`/SHA-1)的跳板 |
| 测试友好 | 全局 / 产品级 **Dry-Run**:只渲染命令 + 记日志、不触设备;命令块写入模块日志供人工核对 |
| 审计 | 每次下发 `logModuleCall` + 可选 `mod_ipdelivery_log`(命令块 / 设备回显 / 结果) |

---

## 工作原理

```
WHMCS 客户下单 ──▶ CreateAccount ──▶ 从该设备的 IPAM 清单挑空闲资源
                                       │
                                       ▼
                         渲染 VRP 命令块 (Templates)
                                       │
                  direct 直连 ┌────────┴────────┐ jump 经跳板
                              ▼                 ▼
                        接入交换机 (华为 VRP) ◀── sshpass ── 跳板
                                       │
                       XC: vlan + Vlanif(/30) + port access
                           + ip route-static + 端口 qos lr 限速
                       GRE: LoopBack + Tunnel(gre, dst=客户IP)
                           + ip route-static
                                       │
                                     save
```

- **XC(物理交叉连接)**:客户物理链路接到交换机某端口。插件配 VLAN、Vlanif(/30 点对点互联)、端口 access、指向客户侧的静态路由,并在物理口上用 `qos lr inbound/outbound cir` 做按客户限速(一口一客户)。
- **GRE(隧道)**:插件配 LoopBack(隧道源)、Tunnel 接口(`tunnel-protocol gre`,destination = 客户公网 IP)、指向 Tunnel 的静态路由。
- **交付段前提**:交付的是你**已在上游宣告的聚合内**的更具体段。null0 兜底、BGP 宣告、上游入向放行是**一次性人工前置**——插件**只在接入交换机上切更具体**,永不触碰 BGP / route-policy / 上游路由器。
- **暂停 = 撤静态路由**(可逆、不动接口骨架);**销户 = 按逆序拆净**(路由 → 接口 → VLAN/Tunnel/LoopBack)并校验无残留后才回池。

---

## 环境要求

- **WHMCS** 9.x(开发与测试基于 9.0.4)
- **PHP** 8.3+
- **MariaDB / MySQL**(WHMCS 自带的 Capsule)
- 一台或多台 **华为 VRP 兼容**接入交换机,可经 direct 或 jump 方式 SSH 到达
- 交换机上一个**最小权限自动化账号**(命令级仅放 `vlan` / `interface` / `ip route-static` / `traffic` / `qos` / `save`)

> 隧道 `tunnel-protocol` 仅支持 `gre`(及 `ipv6-ipv4`);IPv4 隧道交付即 GRE。

---

## 安装

详见 **[docs/INSTALL.md](docs/INSTALL.md)**。简要:

1. **上传**:把 `modules/servers/owp_ipdelivery/` 与 `modules/addons/owp_ipdelivery/` 上传到 WHMCS 的 `modules/` 下(保持路径)。目录 755 / 文件 644 / owner 同站点。
2. **激活**:后台 → *Setup → Addon Modules* → 「IP Delivery」→ **Activate**(幂等自动建 `mod_ipdelivery_*` 表)。授权可访问该 addon 的管理员角色。
3. **加设备**:进 addon 管理页「设备 / Devices」区,新增交换机连接配置 + 凭据,**Test Connection** 验证。
4. **配资源**:在「资源 / Resources」区按你的可交付聚合 / 空闲端口填入 IPAM 清单。
5. **建产品**:Type = *Other*,Module = *IP Delivery*,加 `delivery_type` / `bandwidth` / `prefix_size`(多设备再加 `node`)Configurable Options 与 `Remote Endpoint IP` / `XC Port` 自定义字段。
6. **测试**:开 Dry-Run,下单核对命令块 → 关 Dry-Run 用安全测试值真开 → 全流程过后放量。

> server 模块**必须**安装(addon 共用其 `lib/`)。改 hooks 后 deactivate→activate 一次刷新 hook 缓存。

---

## 配置概览

连接配置与凭据**不在** addon 的 *Configure*,而在 **addon 管理页**(Addons 菜单点「IP Delivery」)的可视化区:

- **设备 / Devices**:每台交换机一条(direct/jump、设备/跳板/写账号、KEX、超时);敏感凭据加密存库;支持增删改启停、按设备 Test Connection;有在用分配的设备拒删。
- **资源 / Resources**:清单式 IPAM(下一节)。
- **占用总览 / Allocations**:按设备 + 交付类型分组查看在用分配。

addon *Configure* 只留全局项:`globalDryRun`、`enabledTypes`(启用的交付类型)、`frontendTypes`(前端开放下单的类型)。

---

## 清单式 IPAM

每类资源以**具体条目**形式逐条管理,**占用状态由在用分配实时计算**——开通一个服务,对应条目自动变「占用·服务#」;销户后自动「空闲」回清单,**无需任何手工排除**。

- **两种录入**:① 母段切分(填母段 + 选切分掩码 → 批量切成条目);② 手动逐条(单条任意值 / 任意掩码)。
- **掩码自选**:PTP / Prefix / Loopback 的切分掩码可选(不写死 /30 /32)。
- **保存即校验**:每次保存先查 *格式 / 重复 / 重叠*;不通过则拦下提示,确需写入可点「⚠ 强制」绕过并留痕。
- **占用保护**:占用中的条目锁定,不可编辑 / 停用 / 删除(需先销户释放);占用标记可点击跳转到对应服务页。
- **批量删除**:每类可勾选多条(或「全选本类空闲」)一键删除,占用中的自动跳过。

---

## 限速与隧道说明

- **XC** 用物理口 `qos lr inbound/outbound cir <kbps>` 按客户限速:一口一客户,**不占用交换机紧张的出向 ACL slice**,可规模化。
- **GRE 隧道本设备不限速**:VRP 的 Tunnel 接口不支持 `qos` / `traffic-policy`;插件只开通隧道、带宽仅记录。若需对隧道限速,建议在下挂的软路由(如 RouteOS)旁路实现。
- 交付类型是**注册表式、可扩展**的(`lib/Types.php`):新增协议只需加一项 + 配套分配 / 模板,核心开通 / 拆除分发不变。

---

## 目录结构

```
modules/
  servers/owp_ipdelivery/
    owp_ipdelivery.php       # server 模块:开通/暂停/恢复/销户 + 客户区 + Admin 按钮
    hooks.php                # 下单交互:freeports/types/nodes AJAX + 购物车 JS
    clientarea.tpl           # GRE 客户区模板(改对端)
    lib/
      Schema.php             # 建表 / 迁移(Capsule,幂等)
      Config.php             # 全局设置 + 每设备加密凭据
      Devices.php            # 多设备 CRUD + 连接配置组装
      Resources.php          # 清单式 IPAM:逐条资源 + 实时占用 + 校验 + 母段切分
      Types.php              # 交付类型注册表(可配置 / 可扩展)
      Ipam.php               # 分配 / 回收(事务 + 行锁,从清单挑空闲条目)
      Templates.php          # 渲染 XC / GRE / 拆除 / 暂停 的 VRP 命令块
      Connection.php         # SSH 连接层(direct / jump)+ 自带 phpseclib v3
      sshv3/                 # 捆绑 phpseclib3 + constant_time(MIT)
  addons/owp_ipdelivery/
    owp_ipdelivery.php       # addon 模块:设备 / 资源 / 占用 管理页
    hooks.php                # 桥接:require server 模块的 hooks.php
install/schema.sql           # 等效建表 SQL(参考;正常由 activate 自动建)
docs/INSTALL.md              # 完整安装与上线手册
```

两模块同名 `owp_ipdelivery`,共用 `mod_ipdelivery_*` 表;addon 通过 `require_once` 引用 server 模块的 `lib/`(单一副本)。

---

## 技术栈

- **PHP 8.3** / WHMCS Module SDK(server + addon 双模块)
- **WHMCS Capsule**(Eloquent)做 DB 与迁移
- **phpseclib v3**(捆绑于 `lib/sshv3/`)做 direct 模式 SSH;jump 模式用系统 `ssh` + `sshpass`
- 纯 PHP IPv4 CIDR 运算,无外部依赖

---

## 安全

- 交付物**零明文密钥**:连接凭据全部经 addon 加密字段存库,或用 WHMCS 主机上的私钥文件。
- 设备改动经**最小权限写账号**;每次下发有 `logModuleCall` + 可选结构化审计日志。
- 物理布线、客户自带 IP/AS、上游 BGP 预置均为线下 / 人工,不在插件职责内。
- 插件**只动接入交换机**,绝不触碰 BGP / route-policy / 上游路由器。

---

## License

[MIT](LICENSE) © 2026 wingsrabbit

捆绑的第三方库 **phpseclib**(MIT)与 **paragonie/constant_time_encoding**(MIT)各自版权归原作者,许可证见 `modules/servers/owp_ipdelivery/lib/sshv3/*-LICENSE.txt`。
