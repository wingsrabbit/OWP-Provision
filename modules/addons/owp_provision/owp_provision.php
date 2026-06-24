<?php
/**
 * IP-Delivery — WHMCS Addon Module（设备 + 凭据 + IPAM 后台页）
 * ============================================================================
 * 与同名 server 模块成对，共用同一套 DB 表与 lib/（addon require server 目录下的 lib）。
 *
 * 产品化设计：
 *  - **多设备**：连接配置不再是 addon 全局单台，而是 `mod_owp_provision_devices` 多台，
 *    在本页「设备 / Devices」区自由增删改启停 + 每设备 Test Connection。
 *  - **每设备凭据**（写/读/跳板密码、私钥口令、私钥内容）`EncryptPassword` 加密存
 *    `mod_owp_provision_config`（key 带 `dev{id}_` 前缀），与设备连接配置同表单「保存即覆盖」。
 *  - **资源池按类型分区 + 单条可编辑**：每设备 → 每 kind（vlan/ptp/prefix/port/loopback/
 *    tunnel/acl）独立列表，行内改 value/meta/enabled、删除、启停、按类型新增。
 *  - **占用总览**按 设备 + 交付类型 分组。
 *
 * addon `_config` 只留**全局非敏感**项（globalDryRun / enabledTypes / frontendTypes）。
 * CSRF：自包含一次性 nonce（`ipd_token` ↔ `$_SESSION['ipd_csrf']`）。全部 Capsule + localAPI，
 * 零硬编码凭据。PHP 8.3。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

use WHMCS\Database\Capsule;
use OwpProvision\Schema;
use OwpProvision\Config;
use OwpProvision\Devices;
use OwpProvision\Servers;
use OwpProvision\Ipam;
use OwpProvision\Resources;
use OwpProvision\Connection;
use OwpProvision\Types;
use OwpProvision\Pools;
use OwpProvision\Lines;

if (!defined('WHMCS')) {
    die('Access Denied');
}

// ---- 共用 lib：require server 模块目录下的 lib（同一套表/逻辑） -----------------
$ipdLibDir = dirname(__DIR__, 2) . '/servers/owp_provision/lib';
require_once $ipdLibDir . '/Schema.php';
require_once $ipdLibDir . '/Config.php';
require_once $ipdLibDir . '/Devices.php';
require_once $ipdLibDir . '/Servers.php';
require_once $ipdLibDir . '/Types.php';
require_once $ipdLibDir . '/Ipam.php';
require_once $ipdLibDir . '/Pools.php';
require_once $ipdLibDir . '/Lines.php';
require_once $ipdLibDir . '/Resources.php';
require_once $ipdLibDir . '/Templates.php';
require_once $ipdLibDir . '/Connection.php';
require_once $ipdLibDir . '/Drivers/DriverInterface.php';
require_once $ipdLibDir . '/Drivers/VrpDriver.php';
require_once $ipdLibDir . '/Drivers/RosDriver.php';

/** 资源池类型（kind）→ 友好名 + value 输入提示。顺序即页面展示顺序。 */
function owpprov_admin_pool_kinds(): array
{
    return [
        'vlan'     => ['VLAN', '整数范围，如 1000-1100 或 100,200-210'],
        'ptp'      => ['PTP /30 母段', 'CIDR 母段，按 /30 切，如 100.64.0.0/24'],
        'tunnel'   => ['Tunnel-ID', '整数范围，如 1000-1999'],
        'prefix'   => ['交付前缀聚合', '上游已宣告聚合，按订单掩码切，如 203.0.113.0/24'],
        'port'     => ['物理端口 Port', '逗号分隔端口名，如 GE1/0/1,GE1/0/2'],
        'loopback' => ['Loopback /32 母段', 'CIDR 母段，按 /32 切，如 198.51.100.0/28'],
        'acl'      => ['高级 ACL 号', '整数范围（GRE 限速用），如 3000-3999'],
        'vpn_ip'   => ['VPN 客户地址 /32', 'RouterOS 给客户 pin 的 VPN 地址；母段按 /32 切，如 10.0.1.0/24'],
    ];
}

// ============================================================================
// _config / _activate / _deactivate / _upgrade
// ============================================================================

/**
 * addon 配置：**只放全局非敏感**项。连接配置 + 凭据按设备在本页「设备」区管理。
 * @return array
 */
function owp_provision_config()
{
    return [
        'name'     => 'IP Delivery',
        'version'  => Schema::VERSION,
        'author'   => 'IP Delivery Module',
        'language' => 'english',
        'fields'   => [
            'globalDryRun' => [
                'FriendlyName' => '全局 Dry-Run',
                'Type'         => 'yesno',
                'Description'  => '勾选：所有产品只渲染命令+记日志、不触设备（内部测试期开）。连接配置/凭据/资源池在本模块页面（非此 Configure）的「设备」「资源池」区管理。',
            ],
            'enabledTypes' => [
                'FriendlyName' => '启用的交付类型 / Enabled Types',
                'Type'         => 'dropdown',
                'Options'      => 'xc,xc+gre,gre',
                'Default'      => 'xc+gre',
                'Description'  => '允许开通（含 admin 手动）的类型。`xc+gre` = 两者都启用。',
            ],
            'frontendTypes' => [
                'FriendlyName' => '前端开放下单的类型 / Frontend Types',
                'Type'         => 'dropdown',
                'Options'      => 'xc,xc+gre,gre',
                'Default'      => 'xc',
                'Description'  => '客户下单页能看到/选到的类型（与 enabled 取交集）。',
            ],
            'mgmtSrcIp' => [
                'FriendlyName' => 'iDRAC 管理源 IP / Mgmt Src IP',
                'Type'         => 'text', 'Size' => '24',
                'Description'  => '开通时 ROS 临时 DNAT 只放行此源（本 WHMCS 主机公网 IP）去配 iDRAC。留空=不自动建 iDRAC 账号。',
            ],
            'dnatPortBase' => [
                'FriendlyName' => 'iDRAC DNAT 端口基数 / DNAT Port Base',
                'Type'         => 'text', 'Size' => '8', 'Default' => '20000',
                'Description'  => '临时管理 DNAT 公网端口 = 此基数 + serviceid（避开已用端口段）。',
            ],
            'dnatSettleDelay' => [
                'FriendlyName' => 'iDRAC DNAT 生效等待秒 / DNAT Settle Delay',
                'Type'         => 'text', 'Size' => '6', 'Default' => '2',
                'Description'  => 'dnatOpen 下发 src-nat 规则后等待秒数（1~10），让规则对新连接生效再发 Redfish，避免首调 HTTP 000。配合 DracDriver 的连接失败重试。',
            ],
        ],
    ];
}

/** 激活：建表（Schema 幂等）。 */
function owp_provision_activate()
{
    return Schema::install();
}

/** 停用：默认不删表（避免误删占用/凭据/设备记录）。 */
function owp_provision_deactivate()
{
    return [
        'status'      => 'success',
        'description' => '已停用。数据表与记录保留（如需彻底清理，请手动删除 mod_owp_provision_* 表）。',
    ];
}

/** 升级：按已装版本迁移（含 1.2.0 多设备迁移）。 */
function owp_provision_upgrade($vars)
{
    try {
        Schema::migrate((string) ($vars['version'] ?? '0'));
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('[IPDelivery] upgrade 迁移异常：' . $e->getMessage());
        }
    }
}

// ============================================================================
// _output（后台管理页）
// ============================================================================

function owp_provision_output($vars)
{
    Schema::ensureTables();
    $modulelink = $vars['modulelink'] ?? 'addonmodules.php?module=owp_provision';
    $action     = $_REQUEST['action'] ?? '';
    $notice     = '';
    $err        = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!owpprov_admin_check_token()) {
            $err = '安全校验失败（token 失效），请刷新重试。';
        } else {
            try {
                Config::flush();
                switch ($action) {
                    // ---- 设备 ----
                    case 'device_add':
                        $id = owpprov_admin_device_add();
                        $notice = '已新增设备 #' . $id . '。请确认连接配置/凭据后用 Test Connection 验证。';
                        break;
                    case 'device_save':
                        owpprov_admin_device_save((int) ($_POST['id'] ?? 0));
                        $notice = '已保存设备配置与凭据。';
                        break;
                    case 'device_toggle':
                        Devices::setEnabled((int) ($_POST['id'] ?? 0), !Devices::isEnabled((int) ($_POST['id'] ?? 0)));
                        $notice = '已切换设备启用状态。';
                        break;
                    case 'device_delete':
                        $notice = owpprov_admin_device_delete((int) ($_POST['id'] ?? 0));
                        break;
                    case 'device_test':
                        [$ok, $msg] = owpprov_admin_device_test((int) ($_POST['id'] ?? 0));
                        if ($ok) { $notice = '连接测试通过：' . $msg; } else { $err = '连接测试失败：' . $msg; }
                        break;
                    case 'device_setup_vpn': // P3：下发 ROS 基础 VPN 配置
                        [$ok, $msg] = owpprov_admin_device_setup_vpn((int) ($_POST['id'] ?? 0));
                        if ($ok) { $notice = 'ROS 基础 VPN 配置已下发：' . $msg; } else { $err = '下发失败：' . $msg; }
                        break;
                    // ---- 服务器库存 ----
                    case 'server_add':
                        $sid = owpprov_admin_server_add();
                        $notice = '已新增服务器 #' . $sid . '。';
                        break;
                    case 'server_save':
                        owpprov_admin_server_save((int) ($_POST['id'] ?? 0));
                        $notice = '已保存服务器。';
                        break;
                    case 'server_setstatus':
                        Servers::setStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? 'free') === 'maintenance' ? 'maintenance' : 'free');
                        $notice = '已更新服务器状态。';
                        break;
                    case 'server_delete':
                        $notice = owpprov_admin_server_delete((int) ($_POST['id'] ?? 0));
                        break;
                    // ---- 资源（清单式 IPAM）----
                    case 'res_split':
                        $notice = owpprov_admin_res_split();
                        break;
                    case 'res_add':
                        $notice = owpprov_admin_res_add();
                        break;
                    case 'res_update':
                        $notice = owpprov_admin_res_update((int) ($_POST['id'] ?? 0));
                        break;
                    case 'res_toggle':
                        owpprov_admin_res_toggle((int) ($_POST['id'] ?? 0));
                        $notice = '已切换资源启用状态。';
                        break;
                    case 'res_delete':
                        owpprov_admin_res_delete((int) ($_POST['id'] ?? 0));
                        $notice = '已删除资源条目。';
                        break;
                    case 'res_bulk_delete':
                        $notice = owpprov_admin_res_bulk_delete();
                        break;
                    // ---- 线路（P8）----
                    case 'line_add':
                        Lines::add(trim((string) ($_POST['name'] ?? '')), trim((string) ($_POST['descr'] ?? '')), (int) ($_POST['device_id'] ?? 0) ?: null);
                        $notice = '已新增线路。';
                        break;
                    case 'line_save':
                        Lines::update((int) ($_POST['id'] ?? 0), [
                            'name' => trim((string) ($_POST['name'] ?? '')),
                            'descr' => trim((string) ($_POST['descr'] ?? '')) ?: null,
                            'device_id' => (int) ($_POST['device_id'] ?? 0) ?: null,
                            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
                        ]);
                        $notice = '已保存线路。';
                        break;
                    case 'line_delete':
                        Lines::delete((int) ($_POST['id'] ?? 0));
                        $notice = '已删除线路。';
                        break;
                    // ---- IP 池组（P11）----
                    case 'pool_add':
                        Pools::addGroup(trim((string) ($_POST['name'] ?? '')), (string) ($_POST['purpose'] ?? 'delivery'),
                            (int) ($_POST['line_id'] ?? 0) ?: null, (int) ($_POST['device_id'] ?? 0) ?: null,
                            (int) ($_POST['deliver_min'] ?? 26), (int) ($_POST['deliver_max'] ?? 30));
                        $notice = '已新增 IP 池组。请展开添加原始母段。';
                        break;
                    case 'pool_save':
                        Pools::updateGroup((int) ($_POST['id'] ?? 0), [
                            'name' => trim((string) ($_POST['name'] ?? '')),
                            'purpose' => (string) ($_POST['purpose'] ?? 'delivery') === 'vpn' ? 'vpn' : 'delivery',
                            'line_id' => (int) ($_POST['line_id'] ?? 0) ?: null,
                            'device_id' => (int) ($_POST['device_id'] ?? 0) ?: null,
                            'deliver_min' => (int) ($_POST['deliver_min'] ?? 26),
                            'deliver_max' => (int) ($_POST['deliver_max'] ?? 30),
                            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
                        ]);
                        $notice = '已保存 IP 池组。';
                        break;
                    case 'pool_delete':
                        Pools::deleteGroup((int) ($_POST['id'] ?? 0));
                        $notice = '已删除 IP 池组（含其母段）。';
                        break;
                    case 'poolblock_add':
                        Pools::addBlock((int) ($_POST['group_id'] ?? 0), trim((string) ($_POST['cidr'] ?? '')));
                        $notice = '已添加母段。';
                        break;
                    case 'poolblock_delete':
                        Pools::deleteBlock((int) ($_POST['id'] ?? 0));
                        $notice = '已删除母段。';
                        break;
                    // ---- 分配（纠偏） ----
                    case 'alloc_release':
                        Ipam::release((int) ($_POST['serviceid'] ?? 0));
                        $notice = '已手动释放（标 terminated，资源回池）。注意：未触设备，仅改记录。';
                        break;
                    case 'alloc_setstatus':
                        Ipam::setStatus((int) ($_POST['serviceid'] ?? 0), (string) ($_POST['status'] ?? 'active'));
                        $notice = '已更新分配状态。';
                        break;
                }
            } catch (\Throwable $e) {
                $err = '操作失败：' . $e->getMessage();
            }
        }
    }

    echo owpprov_admin_styles();
    if ($notice !== '') {
        echo '<div class="alert alert-success">' . htmlspecialchars($notice, ENT_QUOTES) . '</div>';
    }
    if ($err !== '') {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err, ENT_QUOTES) . '</div>';
    }

    echo owpprov_admin_queue_panel($modulelink);
    echo owpprov_admin_devices_panel($modulelink);
    echo owpprov_admin_lines_pools_panel($modulelink);
    echo owpprov_admin_servers_panel($modulelink);
    echo owpprov_admin_resources_panel($modulelink);
    echo owpprov_admin_allocations_panel($modulelink);
}

// ============================================================================
// 面板：设备 / Devices
// ============================================================================

/** 设备角色标签（复用 driver 即角色，P4，不加列）：vrp=接入交换机 / ros=VPN·IPMI 网关 / drac=BMC。 */
function owpprov_device_role_label(string $driver): string
{
    switch (strtolower($driver)) {
        case 'ros':  return 'VPN/IPMI 网关';
        case 'drac': return 'BMC';
        default:     return '接入交换机';
    }
}

function owpprov_admin_devices_panel(string $modulelink): string
{
    $devices = Devices::all();
    $dry     = Config::globalDryRun() ? '🟡 全局 Dry-Run 开启（不触设备）' : '';

    $html  = '<div class="ipd-card"><h3>设备 / Devices' . ($dry !== '' ? ' <small style="color:#8a6d3b">' . $dry . '</small>' : '') . '</h3>';
    $html .= '<p style="color:#666">每台接入交换机一条连接配置 + 独立加密凭据。下单时按「节点」Configurable Option 选设备；'
        . '单个启用设备时前端免选、后端默认用它。</p>';

    // 列表
    $html .= '<table class="table table-condensed table-striped"><thead><tr>'
        . '<th>ID</th><th>名称</th><th>角色 / Role</th><th>模式</th><th>设备</th><th>跳板</th><th>写账号</th><th>启用</th><th>在用分配</th>'
        . '</tr></thead><tbody>';
    foreach ($devices as $d) {
        $active = Devices::hasActiveAllocations((int) $d->id);
        $html .= '<tr>'
            . '<td>#' . (int) $d->id . '</td>'
            . '<td><strong>' . htmlspecialchars((string) $d->name, ENT_QUOTES) . '</strong></td>'
            . '<td><small>' . htmlspecialchars(owpprov_device_role_label((string) ($d->driver ?? 'vrp')), ENT_QUOTES) . '（' . htmlspecialchars((string) ($d->driver ?? 'vrp'), ENT_QUOTES) . '）</small></td>'
            . '<td>' . htmlspecialchars((string) $d->conn_mode, ENT_QUOTES) . '</td>'
            . '<td><small>' . htmlspecialchars(((string) $d->device_host) . ':' . ((string) $d->device_port), ENT_QUOTES) . '</small></td>'
            . '<td><small>' . ((string) $d->conn_mode === 'jump' ? htmlspecialchars(((string) $d->jump_host) . ':' . ((string) $d->jump_port), ENT_QUOTES) : '—') . '</small></td>'
            . '<td><small>' . htmlspecialchars((string) $d->write_user, ENT_QUOTES) . '</small></td>'
            . '<td>' . ((int) $d->enabled === 1 ? '✅' : '—') . '</td>'
            . '<td>' . ($active ? '<span style="color:#8a6d3b">有</span>' : '<small>无</small>') . '</td>'
            . '</tr>';
    }
    if (count($devices) === 0) {
        $html .= '<tr><td colspan="9"><em>暂无设备。请用下方表单新增第一台。</em></td></tr>';
    }
    $html .= '</tbody></table>';

    // 每设备：可展开的「编辑（连接+凭据）」表单 + Test/启停/删除
    foreach ($devices as $d) {
        $html .= '<details class="ipd-dev"><summary>编辑设备 #' . (int) $d->id . '：'
            . htmlspecialchars((string) $d->name, ENT_QUOTES) . '</summary>';
        $html .= owpprov_admin_device_form($modulelink, $d);
        $html .= '<div style="margin-top:8px">'
            . owpprov_admin_mini_form($modulelink, 'device_test', ['id' => (int) $d->id], 'Test Connection（写账号→display version）', 'btn-default')
            . ' '
            . ((string) ($d->driver ?? '') === 'ros'
                ? owpprov_admin_mini_form($modulelink, 'device_setup_vpn', ['id' => (int) $d->id], '下发 ROS VPN 配置', 'btn-default', '幂等下发 /ip pool + 默认 profile + L2TP server（用该 ROS 的 vpn 池组母段）。确认下发？') . ' '
                : '')
            . owpprov_admin_mini_form($modulelink, 'device_toggle', ['id' => (int) $d->id], (int) $d->enabled === 1 ? '停用设备' : '启用设备', 'btn-default')
            . ' '
            . owpprov_admin_mini_form($modulelink, 'device_delete', ['id' => (int) $d->id], '删除设备', 'btn-danger', '确认删除该设备？（有在用分配会被拒绝）')
            . '</div>';
        $html .= '</details>';
    }

    // 新增设备
    $html .= '<details class="ipd-dev"><summary><strong>＋ 新增设备 / Add Device</strong></summary>';
    $html .= owpprov_admin_device_form($modulelink, null);
    $html .= '</details>';

    $html .= '</div>';
    return $html;
}

/**
 * 设备表单（连接配置 + 凭据，一处「保存即覆盖」）。$d=null 时为新增。
 * 敏感字段用 password/textarea 掩码并**预填当前值**（https+admin 下可接受，保存即覆盖）；
 * 非敏感字段明文 text。
 */
function owpprov_admin_device_form(string $modulelink, ?object $d): string
{
    $isNew = ($d === null);
    $id    = $isNew ? 0 : (int) $d->id;
    $act   = $isNew ? 'device_add' : 'device_save';

    $v = static function (string $field, string $default = '') use ($d): string {
        return htmlspecialchars((string) ($d->{$field} ?? $default), ENT_QUOTES);
    };
    // 预填当前凭据（解密）
    $sec = static function (string $k) use ($id): string {
        return $id > 0 ? htmlspecialchars(Config::deviceSecret($id, $k), ENT_QUOTES) : '';
    };
    $modeSel = static function (string $opt) use ($d): string {
        $cur = (string) ($d->conn_mode ?? 'jump');
        return $cur === $opt ? ' selected' : '';
    };
    $driverSel = static function (string $opt) use ($d): string {
        $cur = (string) ($d->driver ?? 'vrp');
        return $cur === $opt ? ' selected' : '';
    };
    $enabledChecked = ($isNew || (int) ($d->enabled ?? 1) === 1) ? ' checked' : '';

    $url = htmlspecialchars($modulelink, ENT_QUOTES) . '&action=' . $act;
    $h   = '<form method="post" action="' . $url . '" class="ipd-dev-form">';
    $h  .= owpprov_admin_token_field();
    $h  .= '<input type="hidden" name="action" value="' . $act . '" />';
    if (!$isNew) {
        $h .= '<input type="hidden" name="id" value="' . $id . '" />';
    }

    $h .= '<div class="ipd-grid">';
    // 非敏感（明文 text）
    $h .= owpprov_field('name', '名称 / Name', $v('name'), 'text', '如 Edge-A');
    $h .= '<div class="ipd-f"><label>设备类型 / Driver（角色）</label><select name="driver" id="owp-driver-select" class="form-control">'
        . '<option value="vrp"' . $driverSel('vrp') . '>vrp — 接入交换机 / Access switch</option>'
        . '<option value="ros"' . $driverSel('ros') . '>ros — VPN/IPMI 网关 / VPN·IPMI gateway</option>'
        . '</select></div>';
    $h .= '<div class="ipd-f"><label>启用 / Enabled</label><div><label class="ipd-chk"><input type="checkbox" name="enabled" value="1"' . $enabledChecked . '> 启用</label></div></div>';
    $h .= '<div class="ipd-f"><label>连接方式 / Mode</label><select name="conn_mode" class="form-control">'
        . '<option value="jump"' . $modeSel('jump') . '>jump（经跳板）</option>'
        . '<option value="direct"' . $modeSel('direct') . '>direct（直连）</option>'
        . '</select></div>';
    $h .= owpprov_field('device_host', '设备 IP / Device Host', $v('device_host'), 'text', '如 192.0.2.20');
    $h .= owpprov_field('device_port', '设备端口', $v('device_port', '22'), 'text', '22');
    $h .= owpprov_field('write_user', '写账号用户名 / Write User', $v('write_user'), 'text', '最小权限自动化账号');
    $h .= owpprov_field('read_user', '读账号用户名(可选)', $v('read_user'), 'text', '留空=用写账号');
    $h .= owpprov_field('kex', 'KEX 算法(可选)', $v('kex'), 'text', 'jump 内层 ssh 用，设备旧 KEX 时填');
    $h .= owpprov_field('jump_host', '跳板主机 / Jump Host', $v('jump_host'), 'text', 'direct 模式留空');
    $h .= owpprov_field('jump_port', '跳板端口', $v('jump_port', '22'), 'text', '22');
    $h .= owpprov_field('jump_user', '跳板用户', $v('jump_user', 'root'), 'text', 'root');
    $h .= owpprov_field('jump_key_path', '跳板私钥路径(可选)', $v('jump_key_path'), 'text', '绝对路径；或下方粘贴私钥内容');
    $h .= owpprov_field('timeout', '超时(秒)', $v('timeout', '30'), 'text', '30');
    // 敏感（password 掩码，预填当前值；保存即覆盖，清空=清除）
    $h .= owpprov_field('writePass', '写账号密码 / Write Password', $sec('writePass'), 'password', '保存即覆盖；清空=清除');
    $h .= owpprov_field('readPass', '读账号密码(可选)', $sec('readPass'), 'password', '');
    $h .= owpprov_field('jumpPass', '跳板密码(可选, jump)', $sec('jumpPass'), 'password', '');
    $h .= owpprov_field('jumpKeyPassphrase', '跳板私钥口令(可选)', $sec('jumpKeyPassphrase'), 'password', '');
    $h .= '</div>'; // grid

    // 私钥内容（textarea，预填当前值）
    $keyText = $id > 0 ? htmlspecialchars(Config::deviceSecret($id, 'jumpKeyText'), ENT_QUOTES) : '';
    $h .= '<div class="ipd-f" style="margin-top:8px"><label>跳板私钥内容 / Jump Private Key (jump)</label>'
        . '<textarea name="jumpKeyText" class="form-control" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----" style="font-family:monospace;font-size:12px">' . $keyText . '</textarea>'
        . '<small style="color:#888">连接时仅在内存使用、不落盘。保存即覆盖；清空=清除。RouterOS(ros) 用同一把私钥登录也填这里。</small></div>';

    // ROS（driver=ros）站点字段：仅 RouterOS / VPN·IPMI 网关用。交换机(vrp)无关 → 按角色显隐(P5)。
    $h .= '<div id="owp-ros-fields">';
    $h .= '<div style="margin-top:8px;color:#31708f;font-size:12px">RouterOS（VPN/IPMI 网关）站点字段：</div>';
    $h .= '<div class="ipd-grid">';
    $h .= owpprov_field('ros_lan_if', 'ROS 内网接口 / LAN if', $v('ros_lan_if'), 'text', 'IPMI 侧接口名，如 lan-edge');
    $h .= owpprov_field('ros_wan_if', 'ROS 公网接口 / WAN if', $v('ros_wan_if'), 'text', '如 wan-uplink');
    $h .= owpprov_field('ros_l2tp_local', 'VPN 本端地址', $v('ros_l2tp_local'), 'text', '如 10.0.0.254');
    $h .= owpprov_field('ros_pub_host', 'VPN 公网地址(客户连)', $v('ros_pub_host'), 'text', '客户连 VPN 的公网主机名/地址，可填域名；空=回退连接 IP');
    $h .= owpprov_field('ros_ikev2_peer', 'IKEv2 peer 名(可选)', $v('ros_ikev2_peer'), 'text', '全局 IKEv2 peer；空=不开 IKEv2');
    $h .= owpprov_field('ros_ipsec_psk', 'IPsec PSK', $sec('ros_ipsec_psk'), 'password', 'L2TP/IPsec 共享密钥（加密存）');
    $h .= '</div></div>'; // /owp-ros-fields

    $h .= '<div style="margin-top:10px"><button class="btn btn-primary" type="submit">'
        . ($isNew ? '新增设备 / Create Device' : '保存设备 / Save Device') . '</button></div>';
    $h .= '</form>';
    // P5：按 driver 即时显隐 RouterOS 字段块（vrp 交换机隐藏；ros 显示）。
    $h .= "<script>(function(){var s=document.getElementById('owp-driver-select'),r=document.getElementById('owp-ros-fields');"
        . "if(!s||!r)return;function t(){r.style.display=(s.value==='ros')?'':'none';}s.addEventListener('change',t);t();})();</script>";
    return $h;
}

/** 单个字段（label + input）。$type=text 明文 / password 掩码。 */
function owpprov_field(string $name, string $label, string $value, string $type = 'text', string $hint = ''): string
{
    $auto = $type === 'password' ? ' autocomplete="new-password"' : '';
    $h  = '<div class="ipd-f"><label>' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
    $h .= '<input type="' . $type . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . $value . '" class="form-control"' . $auto . ' />';
    if ($hint !== '') {
        $h .= '<small style="color:#888">' . htmlspecialchars($hint, ENT_QUOTES) . '</small>';
    }
    $h .= '</div>';
    return $h;
}

// ============================================================================
// 面板：开通队列 + 步骤时间线 / Provisioning queue（读 oplog；样式来自 Claude Design）
// ============================================================================

function owpprov_admin_queue_panel(string $modulelink): string
{
    try {
        $rows = Capsule::table(Schema::T_OPLOG)->orderByDesc('id')->limit(3000)->get();
    } catch (\Throwable $e) {
        $rows = [];
    }
    $bySvc = [];
    foreach ($rows as $r) {
        $sid = (int) $r->serviceid;
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySvc[$sid]) && count($bySvc) >= 40) {
            continue;
        }
        $bySvc[$sid][] = $r;
    }
    $devName = [];
    foreach (Devices::all() as $d) {
        $devName[(int) $d->id] = (string) $d->name;
    }

    $css = (string) @file_get_contents(__DIR__ . '/dashboard.css');
    $h   = '<style>' . $css . '</style>';
    // id=owp-p-queue：触发 dashboard.css 既有激活规则（.owp 无 data-tab 时显示队列面板），否则 .owp-panel 默认 display:none。
    $h  .= '<div class="owp"><div class="owp-panel" id="owp-p-queue">';
    $h  .= '<div class="owp-sec"><span class="owp-sec__n">开通队列 / Provisioning · 步骤时间线</span>'
        . '<span class="owp-sec__n" style="color:var(--text-3)">' . count($bySvc) . ' 单 · 严格串行</span><span class="owp-rule"></span></div>';

    if (count($bySvc) === 0) {
        $h .= '<div class="owp-empty"><div class="owp-empty__t">暂无开通/拆除记录</div>'
            . '<div class="owp-empty__s">服务开通后，这里按步显示「卡在哪一步、哪台设备、回显啥」。日志保留 7 天。</div></div>';
        return $h . '</div></div>';
    }

    $h .= '<div id="owp-orders">';
    foreach ($bySvc as $sid => $steps) {
        $steps = array_reverse($steps); // 时间升序
        $lastPhase = (string) end($steps)->phase;
        $batch = array_values(array_filter($steps, static function ($s) use ($lastPhase) {
            return (string) $s->phase === $lastPhase;
        }));
        $hasFail = false;
        $hasRoll = false;
        foreach ($batch as $s) {
            $st = (string) $s->status;
            if ($st === 'failed' || $st === 'rollback_failed') {
                $hasFail = true;
            }
            if ($st === 'rollback') {
                $hasRoll = true;
            }
        }
        $client  = owpprov_admin_client_label($sid);
        $uid     = owpprov_admin_service_uid($sid);
        $alloc   = Ipam::getAllocation($sid);
        $typeTxt = '—';
        $typeMod = 'transit';
        if ($alloc) {
            if (!empty($alloc->vpn_device_id)) {
                $typeTxt = 'server';
                $typeMod = 'lease';
            } else {
                $typeTxt = strtolower((string) $alloc->delivery_type) === 'gre' ? 'GRE' : 'XC';
            }
        }
        $stBadge = $hasFail ? ['failed', '失败'] : ($hasRoll ? ['rolled', '已回滚'] : ['done', '完成']);

        $prog = '';
        foreach ($batch as $s) {
            $st  = (string) $s->status;
            $seg = $st === 'failed' ? 'owp-prog__seg--failed'
                : (($st === 'ok' || $st === 'dryrun') ? 'owp-prog__seg--done'
                : ($st === 'rollback' ? 'owp-prog__seg--rolled' : ''));
            $prog .= '<i class="owp-prog__seg ' . $seg . '"></i>';
        }

        $h .= '<details class="owp-order ' . ($hasFail ? 'owp-order--failed' : '') . '">'
            . '<summary class="owp-order__row"><span class="owp-caret">▸</span>'
            . '<span class="owp-qpos">' . ($hasFail ? '✕' : '✓') . '</span>'
            . '<span class="owp-order__client"><span class="owp-order__name">' . htmlspecialchars($client, ENT_QUOTES) . '</span>'
            . '<span class="owp-order__svc"><a class="owp-link" href="' . htmlspecialchars('clientsservices.php?userid=' . $uid . '&id=' . $sid, ENT_QUOTES) . '" target="_blank" rel="noopener">svc #' . $sid . '</a></span></span>'
            . '<span class="owp-badge owp-badge--type owp-badge--' . $typeMod . '"><span class="owp-badge__d"></span>' . htmlspecialchars($typeTxt, ENT_QUOTES) . '</span>'
            . '<span class="owp-prog">' . $prog . '</span><span class="owp-order__sp"></span>'
            . '<span class="owp-order__elapsed">' . htmlspecialchars($lastPhase, ENT_QUOTES) . '</span>'
            . '<span class="owp-badge owp-badge--' . $stBadge[0] . '"><span class="owp-badge__d"></span>' . $stBadge[1] . '</span>'
            . '</summary><div class="owp-timeline"><ol class="owp-steps">';

        foreach ($batch as $s) {
            $st   = (string) $s->status;
            $fail = ($st === 'failed' || $st === 'rollback_failed');
            $node = $fail ? '✕' : ($st === 'rollback' ? '↺' : '✓');
            $dev  = $s->device_id ? ($devName[(int) $s->device_id] ?? ('#' . (int) $s->device_id)) : '';
            $time = $s->created_at ? substr((string) $s->created_at, 11, 8) : '';
            $resp = trim((string) ($s->response ?? ''));
            $h .= '<li class="owp-step ' . ($fail ? 'owp-step--failed' : 'owp-step--done') . '"><span class="owp-step__node">' . $node . '</span>'
                . '<div class="owp-step__main"><div class="owp-step__head">'
                . '<span class="owp-step__name">' . htmlspecialchars((string) $s->step, ENT_QUOTES) . '</span>'
                . '<span class="owp-step__meta">' . ($dev !== '' ? '<span class="owp-step__device">' . htmlspecialchars($dev, ENT_QUOTES) . '</span>' : '')
                . '<span class="owp-step__time">' . htmlspecialchars($time, ENT_QUOTES) . '</span></span></div>';
            if ($resp !== '') {
                $h .= '<div class="' . ($fail ? 'owp-step__error' : 'owp-step__detail') . '">' . htmlspecialchars(mb_substr($resp, 0, 400), ENT_QUOTES) . '</div>';
            }
            $h .= '</div></li>';
        }
        $h .= '</ol></div></details>';
    }
    $h .= '</div>'; // /owp-orders
    // P7：默认显 4 单，「展开」显 10 单，每页 10 单可翻页（前端分页；最近活动倒序由 oplog id 决定）。
    $h .= '<div class="owp-pager" style="display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--text-3)">'
        . '<button type="button" id="owp-pg-expand" class="btn btn-default btn-xs">展开 (4→10)</button>'
        . '<button type="button" id="owp-pg-prev" class="btn btn-default btn-xs">◀ 上一页</button>'
        . '<span id="owp-pg-info"></span>'
        . '<button type="button" id="owp-pg-next" class="btn btn-default btn-xs">下一页 ▶</button></div>';
    $h .= "<script>(function(){var box=document.getElementById('owp-orders');if(!box)return;"
        . "var items=[].slice.call(box.children),page=0,expanded=false;"
        . "var info=document.getElementById('owp-pg-info'),prev=document.getElementById('owp-pg-prev'),next=document.getElementById('owp-pg-next'),exp=document.getElementById('owp-pg-expand');"
        . "function ps(){return expanded?10:4;}"
        . "function render(){var s=ps(),st=page*s;items.forEach(function(el,i){el.style.display=(i>=st&&i<st+s)?'':'none';});"
        . "info.textContent=items.length?((st+1)+'-'+Math.min(st+s,items.length)+' / '+items.length+' 单'):'0 单';"
        . "prev.disabled=page<=0;next.disabled=(st+s)>=items.length;}"
        . "exp.onclick=function(){expanded=!expanded;page=0;exp.textContent=expanded?'收起 (10→4)':'展开 (4→10)';render();};"
        . "prev.onclick=function(){if(page>0){page--;render();}};next.onclick=function(){if((page+1)*ps()<items.length){page++;render();}};"
        . "render();})();</script>";
    return $h . '</div></div>';
}

// ============================================================================
// 面板：服务器库存 / Servers（租赁·托管）
// ============================================================================

function owpprov_admin_servers_panel(string $modulelink): string
{
    $servers = Servers::all();
    $devById = [];
    foreach (Devices::all() as $d) {
        $devById[(int) $d->id] = (string) $d->name;
    }

    $html  = '<div class="ipd-card"><h3>服务器库存 / Servers（租赁·托管）</h3>';
    $html .= '<p style="color:#666">每台物理机：所在交换机+端口、IPMI、所在 ROS（开 VPN 用）、可用线路、状态。下单 serviceModel=server 的产品时按线路挑空闲机绑定。</p>';
    $html .= '<table class="table table-condensed table-striped"><thead><tr>'
        . '<th>ID</th><th>名称</th><th>交换机</th><th>端口</th><th>IPMI</th><th>ROS</th><th>线路</th><th>状态</th><th>占用</th>'
        . '</tr></thead><tbody>';
    foreach ($servers as $s) {
        $occ = '';
        if ((string) $s->status === 'rented' && $s->serviceid) {
            $uid = owpprov_admin_service_uid((int) $s->serviceid);
            $occ = '<a class="ipd-occ" href="' . htmlspecialchars('clientsservices.php?userid=' . $uid . '&id=' . (int) $s->serviceid, ENT_QUOTES) . '" target="_blank" rel="noopener">服务 #' . (int) $s->serviceid . '</a>';
        }
        $html .= '<tr>'
            . '<td>#' . (int) $s->id . '</td>'
            . '<td><strong>' . htmlspecialchars((string) $s->name, ENT_QUOTES) . '</strong></td>'
            . '<td><small>' . htmlspecialchars($devById[(int) $s->device_id] ?? ('#' . (int) $s->device_id), ENT_QUOTES) . '</small></td>'
            . '<td><small>' . htmlspecialchars((string) $s->port, ENT_QUOTES) . '</small></td>'
            . '<td><small>' . htmlspecialchars((string) ($s->ipmi_ip ?? ''), ENT_QUOTES) . '</small></td>'
            . '<td><small>' . ($s->vpn_device_id ? htmlspecialchars($devById[(int) $s->vpn_device_id] ?? ('#' . (int) $s->vpn_device_id), ENT_QUOTES) : '—') . '</small></td>'
            . '<td><small>' . htmlspecialchars((string) ($s->line ?? ''), ENT_QUOTES) . '</small></td>'
            . '<td>' . owpprov_admin_server_status_badge((string) $s->status) . '</td>'
            . '<td>' . ($occ !== '' ? $occ : '<small>—</small>') . '</td>'
            . '</tr>';
    }
    if (count($servers) === 0) {
        $html .= '<tr><td colspan="9"><em>暂无服务器。下方新增。</em></td></tr>';
    }
    $html .= '</tbody></table>';

    foreach ($servers as $s) {
        $html .= '<details class="ipd-dev"><summary>编辑服务器 #' . (int) $s->id . '：' . htmlspecialchars((string) $s->name, ENT_QUOTES) . '</summary>';
        $html .= owpprov_admin_server_form($modulelink, $s);
        $html .= '<div style="margin-top:8px">';
        if ((string) $s->status !== 'rented') {
            $html .= owpprov_admin_mini_form($modulelink, 'server_setstatus', ['id' => (int) $s->id, 'status' => (string) $s->status === 'maintenance' ? 'free' : 'maintenance'], (string) $s->status === 'maintenance' ? '设为空闲' : '设为维护', 'btn-default');
            $html .= ' ' . owpprov_admin_mini_form($modulelink, 'server_delete', ['id' => (int) $s->id], '删除', 'btn-danger', '确认删除该服务器？(租用中会被拒)');
        } else {
            $html .= '<small style="color:#8a6d3b">租用中：锁定（先销户释放）。</small>';
        }
        $html .= '</div></details>';
    }

    $html .= '<details class="ipd-dev"><summary><strong>＋ 新增服务器 / Add Server</strong></summary>' . owpprov_admin_server_form($modulelink, null) . '</details>';
    $html .= '</div>';
    return $html;
}

/** 服务器编辑/新增表单。$s=null 为新增。 */
function owpprov_admin_server_form(string $modulelink, ?object $s): string
{
    $isNew = ($s === null);
    $id    = $isNew ? 0 : (int) $s->id;
    $act   = $isNew ? 'server_add' : 'server_save';
    $v = static function (string $f, string $def = '') use ($s): string {
        return htmlspecialchars((string) ($s->{$f} ?? $def), ENT_QUOTES);
    };
    // 设备下拉按角色过滤（P4）：交换机槽只列 vrp、ROS 槽只列 ros，避免选错。
    $devOpts = '';
    $rosOpts = '<option value="">（无 / 不开 VPN）</option>';
    foreach (Devices::all() as $d) {
        $sel  = (!$isNew && (int) $s->device_id === (int) $d->id) ? ' selected' : '';
        $rsel = (!$isNew && (int) ($s->vpn_device_id ?? 0) === (int) $d->id) ? ' selected' : '';
        $nm   = htmlspecialchars((string) $d->name, ENT_QUOTES);
        if ((string) $d->driver === 'vrp') { // 接入交换机
            $devOpts .= '<option value="' . (int) $d->id . '"' . $sel . '>' . $nm . '</option>';
        }
        if ((string) $d->driver === 'ros') { // VPN/IPMI 网关
            $rosOpts .= '<option value="' . (int) $d->id . '"' . $rsel . '>' . $nm . '</option>';
        }
    }
    $ikSel = static function (string $opt) use ($s): string {
        return (string) ($s->ipmi_kind ?? 'idrac') === $opt ? ' selected' : '';
    };

    $url = htmlspecialchars($modulelink, ENT_QUOTES) . '&action=' . $act;
    $h   = '<form method="post" action="' . $url . '" class="ipd-dev-form">' . owpprov_admin_token_field()
        . '<input type="hidden" name="action" value="' . $act . '" />';
    if (!$isNew) {
        $h .= '<input type="hidden" name="id" value="' . $id . '" />';
    }
    $h .= '<div class="ipd-grid">';
    $h .= owpprov_field('name', '名称 / Name', $v('name'), 'text', '如 R640-01');
    $h .= '<div class="ipd-f"><label>所在交换机 / Switch</label><select name="device_id" class="form-control">' . $devOpts . '</select></div>';
    $h .= owpprov_field('port', '交换机端口 / Port', $v('port'), 'text', '服务器 NIC 线缆到的口');
    $h .= '<div class="ipd-f"><label>IPMI 所在 ROS（VPN）</label><select name="vpn_device_id" class="form-control">' . $rosOpts . '</select></div>';
    $h .= owpprov_field('ipmi_ip', 'IPMI IP', $v('ipmi_ip'), 'text', '如 192.168.0.10');
    $h .= '<div class="ipd-f"><label>IPMI 类型</label><select name="ipmi_kind" class="form-control">'
        . '<option value="idrac"' . $ikSel('idrac') . '>iDRAC (Dell)</option>'
        . '<option value="ilo"' . $ikSel('ilo') . '>iLO (HPE)</option>'
        . '<option value="generic"' . $ikSel('generic') . '>generic</option>'
        . '</select></div>';
    $h .= owpprov_field('line', '线路标签 / Line', $v('line'), 'text', '对应 prefix 资源 line；空=不限');
    $h .= owpprov_field('ipmi_user', 'iDRAC 管理账号 / BMC Admin User', $v('ipmi_user'), 'text', '建客户子账号用（如 root）');
    $ipmiPass = $id > 0 ? htmlspecialchars(Config::serverSecret($id, 'ipmi_pass'), ENT_QUOTES) : '';
    $h .= owpprov_field('ipmi_pass', 'iDRAC 管理密码 / BMC Admin Pass', $ipmiPass, 'password', '加密存；保存即覆盖，清空=清除');
    $h .= '</div>';
    $h .= '<div class="ipd-f" style="margin-top:8px"><label>规格 / Specs</label>'
        . '<textarea name="specs" class="form-control" rows="2" placeholder="CPU/内存/盘/网卡">' . $v('specs') . '</textarea></div>';
    $h .= '<div style="margin-top:10px"><button class="btn btn-primary" type="submit">' . ($isNew ? '新增服务器' : '保存服务器') . '</button></div>';
    $h .= '</form>';
    return $h;
}

function owpprov_admin_server_status_badge(string $status): string
{
    $map = ['free' => ['#3c763d', '空闲'], 'rented' => ['#8a6d3b', '租用中'], 'maintenance' => ['#777', '维护']];
    [$c, $l] = $map[$status] ?? ['#333', $status];
    return '<span style="color:' . $c . ';font-weight:bold">' . htmlspecialchars($l, ENT_QUOTES) . '</span>';
}

function owpprov_admin_server_fields_from_post(): array
{
    return [
        'name'          => trim((string) ($_POST['name'] ?? '')),
        'device_id'     => (int) ($_POST['device_id'] ?? 0),
        'port'          => trim((string) ($_POST['port'] ?? '')),
        'vpn_device_id' => trim((string) ($_POST['vpn_device_id'] ?? '')),
        'ipmi_ip'       => trim((string) ($_POST['ipmi_ip'] ?? '')),
        'ipmi_kind'     => in_array((string) ($_POST['ipmi_kind'] ?? 'idrac'), ['idrac', 'ilo', 'generic'], true) ? (string) $_POST['ipmi_kind'] : 'idrac',
        'ipmi_user'     => trim((string) ($_POST['ipmi_user'] ?? '')),
        'line'          => trim((string) ($_POST['line'] ?? '')),
        'specs'         => trim((string) ($_POST['specs'] ?? '')),
    ];
}

function owpprov_admin_server_add(): int
{
    $f = owpprov_admin_server_fields_from_post();
    if ($f['name'] === '' || $f['device_id'] <= 0 || $f['port'] === '') {
        throw new \RuntimeException('名称 / 交换机 / 端口 必填。');
    }
    if (!Devices::exists($f['device_id'])) {
        throw new \RuntimeException('所选交换机不存在。');
    }
    $id = Servers::create($f);
    Config::setServerSecret($id, 'ipmi_pass', (string) ($_POST['ipmi_pass'] ?? '')); // 保存即覆盖
    return $id;
}

function owpprov_admin_server_save(int $id): void
{
    if ($id <= 0 || !Servers::get($id)) {
        throw new \RuntimeException('服务器不存在。');
    }
    $f = owpprov_admin_server_fields_from_post();
    if ($f['name'] === '' || $f['device_id'] <= 0 || $f['port'] === '') {
        throw new \RuntimeException('名称 / 交换机 / 端口 必填。');
    }
    Servers::update($id, $f);
    Config::setServerSecret($id, 'ipmi_pass', (string) ($_POST['ipmi_pass'] ?? '')); // 保存即覆盖
}

function owpprov_admin_server_delete(int $id): string
{
    $s = Servers::get($id);
    if (!$s) {
        throw new \RuntimeException('服务器不存在。');
    }
    if ((string) $s->status === 'rented') {
        throw new \RuntimeException('该服务器租用中，请先把相关服务销户后再删除。');
    }
    Servers::delete($id);
    Config::setServerSecret($id, 'ipmi_pass', ''); // 清除其 BMC 管理密码
    return '已删除服务器 #' . $id . '。';
}

// ============================================================================
// 面板：资源 / Resources（清单式 IPAM：按设备 → 按类型，逐条可见，占用自动）
// ============================================================================

function owpprov_admin_resources_panel(string $modulelink): string
{
    $devices = Devices::all();
    $kinds   = owpprov_admin_pool_kinds();

    $html = '<div class="ipd-card"><h3>资源 / Resources（清单式 IPAM：逐条可见、占用自动）</h3>';
    $html .= '<input type="hidden" id="ipd-modulelink" value="' . htmlspecialchars($modulelink, ENT_QUOTES) . '" />';
    $html .= owpprov_admin_bulk_js();
    if (count($devices) === 0) {
        $html .= '<p><em>请先在上方「设备」区新增至少一台设备，再为其配置资源。</em></p></div>';
        return $html;
    }

    foreach ($devices as $dev) {
        $devId  = (int) $dev->id;
        $allocs = Resources::activeAllocations($devId);
        $total  = 0;
        $body   = '';
        foreach ($kinds as $kind => $info) {
            [$kLabel, $kHint] = $info;
            $rows   = Resources::listByDevice($devId, $kind);
            $total += count($rows);
            $isCidr = in_array($kind, Resources::CIDR_KINDS, true);
            $isInt  = in_array($kind, Resources::INT_KINDS, true);

            $body .= '<div class="ipd-kind"><h5>' . htmlspecialchars($kLabel, ENT_QUOTES)
                . ' <code>' . $kind . '</code> <small style="color:#aaa">' . count($rows) . '</small></h5>';
            foreach ($rows as $r) {
                $body .= owpprov_admin_resource_row($modulelink, $r, Resources::occupant($r, $allocs), $isCidr);
            }
            if (count($rows) === 0) {
                $body .= '<div style="color:#aaa;font-size:12px;margin:2px 0 6px">（无）</div>';
            }
            if (count($rows) > 0) {
                $body .= owpprov_admin_res_bulk_bar($kind);
            }
            $body .= owpprov_admin_res_forms($modulelink, $devId, $kind, $isCidr, $isInt, $kHint);
            $body .= '</div>'; // ipd-kind
        }
        $html .= '<details class="ipd-dev"' . ($dev === $devices[0] ? ' open' : '') . '><summary>设备 #' . $devId
            . '：' . htmlspecialchars((string) $dev->name, ENT_QUOTES)
            . ' <small style="color:#888">（' . $total . ' 条资源）</small></summary>' . $body . '</details>';
    }

    $html .= '<p style="color:#888;margin-top:8px"><small>占用由分配**实时计算**（无需手工 exclude）；占用中条目锁定不可改/停/删，点「占用·服务#」跳客户页。'
        . '保存即校验（格式/查重/重叠，不查上游）；不通过可点「⚠强制」绕过并留痕（活动日志）。'
        . 'CIDR 类（PTP/Prefix/Loopback）掩码可在母段切分或手动添加时自选。</small></p>';
    $html .= '</div>';
    return $html;
}

/** 单条资源：空闲→行内可编辑(value/mask/enabled)+启停+删除；占用→锁定+跳客户页链接。 */
function owpprov_admin_resource_row(string $modulelink, object $r, ?object $occ, bool $isCidr): string
{
    $id      = (int) $r->id;
    $valRaw  = (string) $r->value;
    $valDisp = htmlspecialchars($valRaw . ($isCidr ? '/' . (int) $r->mask : ''), ENT_QUOTES);
    $src     = (string) $r->source === 'manual'
        ? '<span class="ipd-src">手动</span>' : '<span class="ipd-src ipd-src-auto">切分</span>';

    $h = '<div class="ipd-pool-row">';
    if ($occ !== null) {
        $sid  = (int) $occ->serviceid;
        $uid  = owpprov_admin_service_uid($sid);
        $link = 'clientsservices.php?userid=' . $uid . '&id=' . $sid;
        $h   .= '<code style="color:#999">#' . $id . '</code> <strong>' . $valDisp . '</strong> ' . $src
            . ' <a class="ipd-occ" href="' . htmlspecialchars($link, ENT_QUOTES) . '" target="_blank" rel="noopener">占用 · 服务 #' . $sid . '</a>'
            . ' <span style="color:#aaa;font-size:11px">（锁定）</span>';
    } else {
        $h .= '<input type="checkbox" class="ipd-bulk" data-kind="' . htmlspecialchars((string) $r->kind, ENT_QUOTES) . '" value="' . $id . '" title="勾选以批量删除" /> ';
        $checked = (int) $r->enabled === 1 ? ' checked' : '';
        $upUrl   = htmlspecialchars($modulelink, ENT_QUOTES) . '&action=res_update';
        $h .= '<form method="post" action="' . $upUrl . '" class="form-inline" style="display:inline">'
            . owpprov_admin_token_field()
            . '<input type="hidden" name="action" value="res_update" />'
            . '<input type="hidden" name="id" value="' . $id . '" />'
            . '<code style="color:#999">#' . $id . '</code> <span class="ipd-free">空闲</span> '
            . '<input type="text" name="value" value="' . htmlspecialchars($valRaw, ENT_QUOTES) . '" class="form-control input-sm" style="width:150px;margin-right:3px" />';
        if ($isCidr) {
            $h .= ' /' . owpprov_admin_mask_select('mask', (int) $r->mask, 'width:72px');
        }
        $h .= ' ' . $src
            . ' <label class="ipd-chk" style="margin:0 6px"><input type="checkbox" name="enabled" value="1"' . $checked . '> 启用</label>'
            . '<button class="btn btn-xs btn-primary" type="submit" name="force" value="">保存</button> '
            . '<button class="btn btn-xs btn-warning" type="submit" name="force" value="1" onclick="return confirm(\'校验未通过也强制保存？会留痕。\');">⚠强制</button>'
            . '</form> ';
        $h .= owpprov_admin_mini_form($modulelink, 'res_toggle', ['id' => $id], (int) $r->enabled === 1 ? '停用' : '启用', 'btn-default');
        $h .= ' ' . owpprov_admin_mini_form($modulelink, 'res_delete', ['id' => $id], '删除', 'btn-danger', '确认删除该资源条目？');
    }
    $h .= '</div>';
    return $h;
}

/** 某类型下的「母段切分」+「手动逐条」两个录入表单（都带 保存=校验 / ⚠强制=绕过）。 */
function owpprov_admin_res_forms(string $modulelink, int $devId, string $kind, bool $isCidr, bool $isInt, string $hint): string
{
    $splitUrl = htmlspecialchars($modulelink, ENT_QUOTES) . '&action=res_split';
    $addUrl   = htmlspecialchars($modulelink, ENT_QUOTES) . '&action=res_add';
    $hidden   = '<input type="hidden" name="device_id" value="' . $devId . '" /><input type="hidden" name="kind" value="' . $kind . '" />';
    $forceBtns = '<button class="btn btn-xs btn-primary" type="submit" name="force" value="">保存</button> '
        . '<button class="btn btn-xs btn-warning" type="submit" name="force" value="1" onclick="return confirm(\'校验未通过也强制？会留痕。\');">⚠强制</button>';

    // 母段切分
    $h  = '<form method="post" action="' . $splitUrl . '" class="form-inline ipd-pool-add">'
        . owpprov_admin_token_field() . '<input type="hidden" name="action" value="res_split" />' . $hidden
        . '<span class="ipd-formlabel">母段切分：</span>'
        . '<input type="text" name="master" class="form-control input-sm" placeholder="' . htmlspecialchars($hint, ENT_QUOTES) . '" style="width:240px;margin-right:3px" />';
    if ($isCidr) {
        $h .= ' 掩码 /' . owpprov_admin_mask_select('split_mask', 30, 'width:72px') . ' ';
    }
    $h .= $forceBtns . '</form>';

    // 手动逐条
    $h .= '<form method="post" action="' . $addUrl . '" class="form-inline ipd-pool-add">'
        . owpprov_admin_token_field() . '<input type="hidden" name="action" value="res_add" />' . $hidden
        . '<span class="ipd-formlabel">手动逐条：</span>'
        . '<input type="text" name="value" class="form-control input-sm" placeholder="' . ($isInt ? '单个整数' : ($isCidr ? 'IP/网络地址' : '单个端口名')) . '" style="width:170px;margin-right:3px" />';
    if ($isCidr) {
        $h .= ' /' . owpprov_admin_mask_select('mask', 32, 'width:72px') . ' ';
    }
    $h .= $forceBtns . '</form>';
    return $h;
}

/** 掩码下拉（/8../32，覆盖 PTP/Loopback/Prefix 各场景；expand 会再校验 split<母段）。 */
function owpprov_admin_mask_select(string $name, int $selected, string $style = ''): string
{
    $h = '<select name="' . $name . '" class="form-control input-sm" style="display:inline-block;' . $style . '">';
    for ($m = 8; $m <= 32; $m++) {
        $h .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>/' . $m . '</option>';
    }
    return $h . '</select>';
}

/** serviceid → userid（占用链接用，进程内缓存）。 */
function owpprov_admin_service_uid(int $serviceId): int
{
    static $cache = [];
    if (isset($cache[$serviceId])) {
        return $cache[$serviceId];
    }
    try {
        $uid = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
    } catch (\Throwable $e) {
        $uid = 0;
    }
    return $cache[$serviceId] = $uid;
}

// ============================================================================
// 面板：占用总览 / Allocations（按设备 + 交付类型 分组）
// ============================================================================

function owpprov_admin_allocations_panel(string $modulelink): string
{
    $devices = Devices::all();
    // P10：默认只显活跃；已销户默认隐藏（记录保留），GET owp_show_term=1 显示供对账。
    $showTerm = !empty($_GET['owp_show_term']);
    $toggleUrl = htmlspecialchars($modulelink . ($showTerm ? '' : '&owp_show_term=1') . '#owp-alloc', ENT_QUOTES);
    $html  = '<div class="ipd-card" id="owp-alloc"><h3>占用总览 / Allocations（按设备 + 类型）'
        . ' <small style="font-weight:normal"><a href="' . $toggleUrl . '">'
        . ($showTerm ? '隐藏已销户 / hide terminated' : '显示已销户 / show terminated') . '</a></small></h3>';
    if (!$showTerm) {
        $html .= '<p style="color:#888;font-size:12px;margin:0 0 6px">仅显示活跃分配；已销户记录已隐藏（点上方链接查看，记录保留不删）。</p>';
    }

    $deviceIds = [];
    $total = 0;
    foreach ($devices as $dev) {
        $deviceIds[] = (int) $dev->id;
        $rows = Capsule::table(Schema::T_ALLOCATIONS)->where('device_id', (int) $dev->id)
            ->when(!$showTerm, function ($q) { return $q->where('status', '!=', 'terminated'); })
            ->orderBy('delivery_type')->orderByDesc('id')->limit(1000)->get();
        $total += count($rows);
        $html .= '<h4 style="margin-top:12px">设备 #' . (int) $dev->id . '：' . htmlspecialchars((string) $dev->name, ENT_QUOTES)
            . ' <small style="color:#888">（' . count($rows) . '）</small></h4>';
        $html .= owpprov_admin_alloc_table($modulelink, $rows);
    }

    // 孤儿：device_id 不在现有设备（设备被删但仍有记录 / 迁移异常）
    $orphans = Capsule::table(Schema::T_ALLOCATIONS)
        ->when(!empty($deviceIds), function ($q) use ($deviceIds) { return $q->whereNotIn('device_id', $deviceIds); })
        ->when(!$showTerm, function ($q) { return $q->where('status', '!=', 'terminated'); })
        ->orderByDesc('id')->limit(500)->get();
    if (count($orphans) > 0) {
        $html .= '<h4 style="margin-top:12px;color:#a94442">未知/已删除设备 <small>（' . count($orphans) . '）</small></h4>';
        $html .= owpprov_admin_alloc_table($modulelink, $orphans);
    }

    if ($total === 0 && count($orphans) === 0) {
        $html .= '<p><em>暂无分配记录。</em></p>';
    }
    $html .= '<p style="color:#a94442"><small>⚠ 「释放/标状态」只改记录，<strong>不连设备</strong>，仅供纠偏/对账。真正拆除请在服务页用模块的 Terminate。</small></p>';
    $html .= '</div>';
    return $html;
}

/** 渲染一张分配表（共用于各设备分组）。 */
function owpprov_admin_alloc_table(string $modulelink, $rows): string
{
    if (count($rows) === 0) {
        return '<div style="color:#aaa;font-size:12px;margin:2px 0 6px">（无）</div>';
    }
    $html  = '<table class="table table-striped table-condensed"><thead><tr>'
        . '<th>svc</th><th>客户</th><th>type</th><th>VLAN</th><th>PTP(our/peer)</th><th>prefix</th>'
        . '<th>port</th><th>tunnel/loop</th><th>remote</th><th>BW</th><th>状态</th><th>操作</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $client = owpprov_admin_client_label((int) $r->serviceid);
        $tun = ((string) $r->delivery_type === 'gre') ? ('T' . ($r->tunnel_id ?? '') . ' / ' . ($r->loopback_ip ?? '')) : '';
        $html .= '<tr>'
            . '<td>' . (int) $r->serviceid . '</td>'
            . '<td>' . htmlspecialchars($client, ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars((string) $r->delivery_type, ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars((string) ($r->vlan_id ?? ''), ENT_QUOTES) . '</td>'
            . '<td><small>' . htmlspecialchars(trim(((string) ($r->ptp_our ?? '')) . ' / ' . ((string) ($r->ptp_peer ?? '')), ' /'), ENT_QUOTES) . '</small></td>'
            . '<td><code>' . htmlspecialchars((string) ($r->prefix ?? ''), ENT_QUOTES) . '</code></td>'
            . '<td><small>' . htmlspecialchars((string) ($r->port ?? ''), ENT_QUOTES) . '</small></td>'
            . '<td><small>' . htmlspecialchars($tun, ENT_QUOTES) . '</small></td>'
            . '<td><small>' . htmlspecialchars((string) ($r->remote_ip ?? ''), ENT_QUOTES) . '</small></td>'
            . '<td>' . htmlspecialchars((string) ($r->bandwidth ?? ''), ENT_QUOTES) . '</td>'
            . '<td>' . owpprov_admin_status_badge((string) $r->status) . '</td>'
            . '<td>';
        if ((string) $r->status !== 'terminated') {
            if ((string) $r->status === 'active') {
                $html .= owpprov_admin_mini_form($modulelink, 'alloc_setstatus', ['serviceid' => (int) $r->serviceid, 'status' => 'suspended'], '标暂停', 'btn-default');
            } else {
                $html .= owpprov_admin_mini_form($modulelink, 'alloc_setstatus', ['serviceid' => (int) $r->serviceid, 'status' => 'active'], '标激活', 'btn-default');
            }
            $html .= ' ' . owpprov_admin_mini_form($modulelink, 'alloc_release', ['serviceid' => (int) $r->serviceid], '释放(回池)', 'btn-warning', '手动释放只改记录、不触设备。确认？');
        } else {
            $html .= '<small>—</small>';
        }
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

// ============================================================================
// 后台动作：设备
// ============================================================================

/** 从 POST 取设备非敏感字段（白名单交给 Devices::sanitize 再过滤）。 */
function owpprov_admin_device_fields_from_post(): array
{
    return [
        'name'          => trim((string) ($_POST['name'] ?? '')),
        'driver'        => (string) ($_POST['driver'] ?? 'vrp') === 'ros' ? 'ros' : 'vrp',
        'enabled'       => !empty($_POST['enabled']) ? 1 : 0,
        'conn_mode'     => (string) ($_POST['conn_mode'] ?? 'jump') === 'direct' ? 'direct' : 'jump',
        'device_host'   => trim((string) ($_POST['device_host'] ?? '')),
        'device_port'   => trim((string) ($_POST['device_port'] ?? '22')),
        'write_user'    => trim((string) ($_POST['write_user'] ?? '')),
        'read_user'     => trim((string) ($_POST['read_user'] ?? '')),
        'kex'           => trim((string) ($_POST['kex'] ?? '')),
        'jump_host'     => trim((string) ($_POST['jump_host'] ?? '')),
        'jump_port'     => trim((string) ($_POST['jump_port'] ?? '22')),
        'jump_user'     => trim((string) ($_POST['jump_user'] ?? 'root')),
        'jump_key_path' => trim((string) ($_POST['jump_key_path'] ?? '')),
        'timeout'       => trim((string) ($_POST['timeout'] ?? '30')),
        'ros_lan_if'    => trim((string) ($_POST['ros_lan_if'] ?? '')),
        'ros_wan_if'    => trim((string) ($_POST['ros_wan_if'] ?? '')),
        'ros_l2tp_local' => trim((string) ($_POST['ros_l2tp_local'] ?? '')),
        'ros_pub_host'   => trim((string) ($_POST['ros_pub_host'] ?? '')),
        'ros_ikev2_peer' => trim((string) ($_POST['ros_ikev2_peer'] ?? '')),
    ];
}

/** 保存该设备的全部敏感凭据（保存即覆盖：提交什么存什么，空=清除）。含 ROS 的 IPsec PSK。 */
function owpprov_admin_device_secrets_from_post(int $deviceId): void
{
    foreach (Config::SECRET_KEYS as $sk) {
        $val = (string) ($_POST[$sk] ?? '');
        if ($sk === 'jumpKeyText') {
            // textarea 换行规范化为 \n，避免污染 PEM。
            $val = str_replace(["\r\n", "\r"], "\n", $val);
        }
        Config::setDeviceSecret($deviceId, $sk, $val); // 空串 → 删除该项
    }
    // ROS IPsec PSK（仅 ros 设备用；同样「保存即覆盖」）
    Config::setDeviceSecret($deviceId, 'ros_ipsec_psk', (string) ($_POST['ros_ipsec_psk'] ?? ''));
}

function owpprov_admin_device_add(): int
{
    $fields = owpprov_admin_device_fields_from_post();
    if ($fields['name'] === '') {
        throw new \RuntimeException('设备名称不能为空。');
    }
    $id = Devices::create($fields);
    owpprov_admin_device_secrets_from_post($id);
    return $id;
}

function owpprov_admin_device_save(int $id): void
{
    if ($id <= 0 || !Devices::exists($id)) {
        throw new \RuntimeException('设备不存在。');
    }
    $fields = owpprov_admin_device_fields_from_post();
    if ($fields['name'] === '') {
        throw new \RuntimeException('设备名称不能为空。');
    }
    Devices::update($id, $fields);
    owpprov_admin_device_secrets_from_post($id);
}

function owpprov_admin_device_delete(int $id): string
{
    if ($id <= 0 || !Devices::exists($id)) {
        throw new \RuntimeException('设备不存在。');
    }
    if (Devices::hasActiveAllocations($id)) {
        throw new \RuntimeException('该设备仍有在用分配（active/suspended），请先把相关服务销户后再删除。');
    }
    Devices::delete($id);
    Resources::deleteByDevice($id); // 同时清空该设备资源清单（无在用分配，安全）
    return '已删除设备 #' . $id . '（其加密凭据、资源清单一并清除）。';
}

/** 单设备 Test Connection（按 driver 分发：vrp=写账号 display version / ros=/system version；忽略全局 dry-run）。 */
function owpprov_admin_device_test(int $id): array
{
    $dev = Devices::get($id);
    if (!$dev) {
        return [false, '设备不存在。'];
    }
    try {
        $driver = strtolower((string) ($dev->driver ?? 'vrp'));
        $drv = $driver === 'ros'
            ? new \OwpProvision\Drivers\RosDriver($id, false)
            : new \OwpProvision\Drivers\VrpDriver($id, false);
        $res = $drv->testConnection();
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
    logModuleCall('owp_provision', 'AddonDeviceTest', ['device_id' => $id, 'driver' => $dev->driver ?? 'vrp'], $res['output'] ?? '', $res['error'] ?? '');
    return [(bool) ($res['ok'] ?? false), ($res['ok'] ?? false) ? mb_substr(trim((string) ($res['output'] ?? '')), 0, 200) : (string) ($res['error'] ?? '')];
}

/** P3：下发 ROS 基础 VPN 配置（建 /ip pool + 默认 profile + 启 L2TP server+PSK）。用该 ROS 的 vpn 池组母段。 */
function owpprov_admin_device_setup_vpn(int $id): array
{
    $dev = Devices::get($id);
    if (!$dev || (string) ($dev->driver ?? '') !== 'ros') {
        return [false, '仅 ROS（VPN/IPMI 网关）设备可下发 VPN 配置。'];
    }
    $group = Pools::findVpnGroup($id);
    if (!$group) {
        return [false, '请先为该 ROS 建 purpose=vpn 的 IP 池组并加母段（如 10.0.0.0/25）。'];
    }
    $cidrs = [];
    foreach (Pools::blocks((int) $group->id) as $b) {
        $cidrs[] = (string) $b->cidr;
    }
    if (!$cidrs) {
        return [false, '该 vpn 池组未加母段。'];
    }
    $local = trim((string) ($dev->ros_l2tp_local ?? ''));
    $psk   = Config::deviceSecret($id, 'ros_ipsec_psk');
    try {
        $drv = new \OwpProvision\Drivers\RosDriver($id, Config::globalDryRun());
        $r   = $drv->setupVpnPool($cidrs, $local, $psk);
        logModuleCall('owp_provision', 'AddonRosVpnSetup', ['device_id' => $id, 'ranges' => $r['ranges'] ?? ''], 'ok', '');
        return [true, 'pool=' . ($r['pool'] ?? '') . ' ranges=' . ($r['ranges'] ?? '') . (!empty($r['dryRun']) ? '（全局 dry-run，未真下发）' : '')];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// ============================================================================
// 后台动作：资源（清单式 IPAM）。保存即校验；force=1 绕过校验并留痕。
// ============================================================================

/** 母段切分入清单（source=auto）。 */
function owpprov_admin_res_split(): string
{
    $devId     = (int) ($_POST['device_id'] ?? 0);
    $kind      = strtolower(trim((string) ($_POST['kind'] ?? '')));
    $master    = trim((string) ($_POST['master'] ?? ''));
    $force     = !empty($_POST['force']);
    $splitMask = (isset($_POST['split_mask']) && $_POST['split_mask'] !== '') ? (int) $_POST['split_mask'] : null;

    if (!Devices::exists($devId)) {
        throw new \RuntimeException('请选择有效设备。');
    }
    if (!in_array($kind, Resources::KINDS, true)) {
        throw new \RuntimeException('非法资源类型：' . $kind);
    }
    if ($master === '') {
        throw new \RuntimeException('母段/范围不能为空。');
    }
    $r = Resources::expand($kind, $master, $splitMask);
    if (!empty($r['errors'])) {
        throw new \RuntimeException('切分失败：' . implode('；', $r['errors']));
    }
    if (empty($r['items'])) {
        throw new \RuntimeException('未解析出任何条目。');
    }
    if (!$force) {
        $errs = Resources::validateMany($devId, $kind, $r['items']);
        if (!empty($errs)) {
            throw new \RuntimeException('校验未通过（可点⚠强制绕过）：'
                . implode('；', array_slice($errs, 0, 8)) . (count($errs) > 8 ? ' …共 ' . count($errs) . ' 条' : ''));
        }
    }
    $n = Resources::addMany($devId, $kind, $r['items'], 'auto', $force ? 'forced' : null);
    if ($force) {
        owpprov_admin_log_forced('res_split', $devId, $kind, $master . ' ×' . $n);
    }
    return '已切分并入清单 ' . $n . ' 条' . ($force ? '（⚠强制，已留痕）' : '') . '。';
}

/** 手动逐条添加（source=manual）。 */
function owpprov_admin_res_add(): string
{
    $devId = (int) ($_POST['device_id'] ?? 0);
    $kind  = strtolower(trim((string) ($_POST['kind'] ?? '')));
    $value = trim((string) ($_POST['value'] ?? ''));
    $force = !empty($_POST['force']);

    if (!Devices::exists($devId)) {
        throw new \RuntimeException('请选择有效设备。');
    }
    if (!in_array($kind, Resources::KINDS, true)) {
        throw new \RuntimeException('非法资源类型：' . $kind);
    }
    if ($value === '') {
        throw new \RuntimeException('值不能为空。');
    }
    $mask = owpprov_admin_res_mask_from_post($kind);
    if (!$force) {
        $errs = Resources::validate($devId, $kind, $value, $mask);
        if (!empty($errs)) {
            throw new \RuntimeException('校验未通过（可点⚠强制绕过）：' . implode('；', $errs));
        }
    }
    Resources::add($devId, $kind, $value, $mask, 'manual', $force ? 'forced' : null);
    if ($force) {
        owpprov_admin_log_forced('res_add', $devId, $kind, $value . ($mask !== null ? '/' . $mask : ''));
    }
    return '已添加资源 ' . $value . ($mask !== null ? '/' . $mask : '') . ($force ? '（⚠强制，已留痕）' : '') . '。';
}

/** 单条编辑（value/mask/enabled）。占用中锁定。 */
function owpprov_admin_res_update(int $id): string
{
    if ($id <= 0) {
        throw new \RuntimeException('无效资源 ID。');
    }
    $r = Resources::get($id);
    if (!$r) {
        throw new \RuntimeException('资源不存在。');
    }
    if (Resources::isOccupied($id)) {
        throw new \RuntimeException('该资源占用中，锁定不可编辑（请先销户释放）。');
    }
    $kind    = (string) $r->kind;
    $value   = trim((string) ($_POST['value'] ?? ''));
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $force   = !empty($_POST['force']);
    if ($value === '') {
        throw new \RuntimeException('值不能为空。');
    }
    $mask = owpprov_admin_res_mask_from_post($kind);
    if (!$force) {
        $errs = Resources::validate((int) $r->device_id, $kind, $value, $mask, $id);
        if (!empty($errs)) {
            throw new \RuntimeException('校验未通过（可点⚠强制绕过）：' . implode('；', $errs));
        }
    }
    Resources::update($id, $value, $mask, $enabled);
    if ($force) {
        Capsule::table(Schema::T_RESOURCES)->where('id', $id)->update(['note' => 'forced']);
        owpprov_admin_log_forced('res_update', (int) $r->device_id, $kind, $value . ($mask !== null ? '/' . $mask : ''));
    }
    return '已更新资源 #' . $id . ($force ? '（⚠强制，已留痕）' : '') . '。';
}

function owpprov_admin_res_toggle(int $id): void
{
    if ($id <= 0) {
        throw new \RuntimeException('无效资源 ID。');
    }
    if (Resources::isOccupied($id)) {
        throw new \RuntimeException('该资源占用中，不可停用。');
    }
    $r = Resources::get($id);
    if (!$r) {
        throw new \RuntimeException('资源不存在。');
    }
    Resources::setEnabled($id, (int) $r->enabled !== 1);
}

function owpprov_admin_res_delete(int $id): void
{
    if ($id <= 0) {
        throw new \RuntimeException('无效资源 ID。');
    }
    if (Resources::isOccupied($id)) {
        throw new \RuntimeException('该资源占用中，不可删除（请先销户释放）。');
    }
    Resources::delete($id);
}

/** 批量删除选中的资源条目；占用中的服务端再次校验并自动跳过、不删。 */
function owpprov_admin_res_bulk_delete(): string
{
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        throw new \RuntimeException('未选中任何条目。');
    }
    $del = 0; $skip = 0; $miss = 0;
    foreach ($ids as $raw) {
        $id = (int) $raw;
        if ($id <= 0) { continue; }
        if (!Resources::get($id)) { $miss++; continue; }
        if (Resources::isOccupied($id)) { $skip++; continue; }
        Resources::delete($id);
        $del++;
    }
    $msg = '批量删除：成功 ' . $del . ' 条';
    if ($skip > 0) { $msg .= '，跳过 ' . $skip . ' 条（占用中锁定）'; }
    if ($miss > 0) { $msg .= '，' . $miss . ' 条已不存在'; }
    return $msg . '。';
}

/** 某类资源底部的批量删除控件条（全选本类空闲 + 删除选中）。 */
function owpprov_admin_res_bulk_bar(string $kind): string
{
    $k = htmlspecialchars($kind, ENT_QUOTES);
    return '<div class="ipd-bulkbar">'
        . '<label class="ipd-chk" style="margin-right:8px"><input type="checkbox" onclick="ipdBulkAll(\'' . $k . '\',this.checked)"> 全选本类空闲</label>'
        . '<button type="button" class="btn btn-xs btn-danger" onclick="ipdBulkDel(\'' . $k . '\')">删除选中</button>'
        . '</div>';
}

/** 批量删除的前端 JS（一次输出；token 从页面任一 ipd_token 字段取，避免表单嵌套）。 */
function owpprov_admin_bulk_js(): string
{
    return <<<'HTML'
<script>
function ipdBulkAll(kind,on){
  document.querySelectorAll('.ipd-bulk[data-kind="'+kind+'"]').forEach(function(c){c.checked=on;});
}
function ipdBulkDel(kind){
  var cbs=document.querySelectorAll('.ipd-bulk[data-kind="'+kind+'"]:checked');
  if(!cbs.length){alert('未选中任何条目。');return;}
  if(!confirm('确认删除选中的 '+cbs.length+' 条 '+kind+' 资源？占用中的会自动跳过、不删除。'))return;
  var tok=document.querySelector('input[name=ipd_token]');
  var ml=document.getElementById('ipd-modulelink');
  var f=document.createElement('form');
  f.method='POST';
  f.action=(ml?ml.value:'addonmodules.php?module=owp_provision')+'&action=res_bulk_delete';
  function add(n,v){var i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;f.appendChild(i);}
  add('action','res_bulk_delete');
  add('ipd_token',tok?tok.value:'');
  add('kind',kind);
  cbs.forEach(function(c){add('ids[]',c.value);});
  document.body.appendChild(f);
  f.submit();
}
</script>
HTML;
}

/** CIDR 类从 POST 读 mask；其余类返回 null。 */
function owpprov_admin_res_mask_from_post(string $kind): ?int
{
    if (!in_array($kind, Resources::CIDR_KINDS, true)) {
        return null;
    }
    return (isset($_POST['mask']) && $_POST['mask'] !== '') ? (int) $_POST['mask'] : null;
}

/** 强制保存（绕过校验）留痕到活动日志，便于回溯。 */
function owpprov_admin_log_forced(string $action, int $devId, string $kind, string $detail): void
{
    if (function_exists('logActivity')) {
        logActivity('[IPDelivery] ⚠ 强制保存资源（绕过校验）：' . $action . ' device#' . $devId . ' ' . $kind . ' ' . $detail);
    }
}

// ============================================================================
// 后台 UI 小工具 + 自包含 CSRF nonce
// ============================================================================

function owpprov_admin_mini_form(string $modulelink, string $action, array $hidden, string $label, string $btnClass = 'btn-default', string $confirm = ''): string
{
    $url  = htmlspecialchars($modulelink, ENT_QUOTES) . '&action=' . urlencode($action);
    $html = '<form method="post" action="' . $url . '" style="display:inline">';
    $html .= owpprov_admin_token_field();
    $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES) . '" />';
    foreach ($hidden as $k => $v) {
        $html .= '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '" />';
    }
    $onclick = $confirm !== '' ? ' onclick="return confirm(\'' . htmlspecialchars($confirm, ENT_QUOTES) . '\');"' : '';
    $html .= '<button type="submit" class="btn btn-xs ' . htmlspecialchars($btnClass, ENT_QUOTES) . '"' . $onclick . '>' . htmlspecialchars($label, ENT_QUOTES) . '</button></form>';
    return $html;
}

/**
 * 自包含 CSRF token 隐藏字段（一次性 nonce）。每个请求生成一次（静态），同请求内多表单复用同值；
 * 存进 $_SESSION['ipd_csrf']。字段名 `ipd_token`。POST 处理在渲染前，故校验的是上次渲染存入的会话值。
 */
function owpprov_admin_token_field(): string
{
    static $t = null;
    if ($t === null) {
        $t = bin2hex(random_bytes(16));
        $_SESSION['ipd_csrf'] = $t;
    }
    return '<input type="hidden" name="ipd_token" value="' . htmlspecialchars($t, ENT_QUOTES) . '" />';
}

/** 校验 nonce：提交值与会话值 hash_equals。 */
function owpprov_admin_check_token(): bool
{
    $submitted = (string) ($_POST['ipd_token'] ?? '');
    $sess      = (string) ($_SESSION['ipd_csrf'] ?? '');
    return $submitted !== '' && $sess !== '' && hash_equals($sess, $submitted);
}

function owpprov_admin_client_label(int $serviceId): string
{
    try {
        $svc = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$svc) {
            return '#' . $serviceId;
        }
        $client = Capsule::table('tblclients')->where('id', (int) $svc->userid)->first();
        if (!$client) {
            return '#' . $serviceId;
        }
        $name = trim((string) ($client->companyname ?? '')) ?: trim(((string) $client->firstname) . ' ' . ((string) $client->lastname));
        return ($name !== '' ? $name : 'client') . ' (#' . (int) $svc->userid . ')';
    } catch (\Throwable $e) {
        return '#' . $serviceId;
    }
}

function owpprov_admin_status_badge(string $status): string
{
    $map = ['active' => ['#3c763d', '激活'], 'suspended' => ['#8a6d3b', '暂停'], 'terminated' => ['#777', '已销户']];
    [$color, $label] = $map[$status] ?? ['#333', $status];
    return '<span style="color:' . $color . ';font-weight:bold">' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
}

/** 线路 + IP 池组管理面板（P8 线路实体 / P11 池组）。 */
function owpprov_admin_lines_pools_panel(string $modulelink): string
{
    $url = htmlspecialchars($modulelink, ENT_QUOTES);
    $tf  = owpprov_admin_token_field();
    $vrp = [];
    $ros = [];
    foreach (Devices::all() as $d) {
        if ((string) $d->driver === 'vrp') { $vrp[(int) $d->id] = (string) $d->name; }
        if ((string) $d->driver === 'ros') { $ros[(int) $d->id] = (string) $d->name; }
    }
    $devName = $vrp + $ros;
    $lines   = Lines::all();

    $h  = '<div class="ipd-card"><h3>线路 &amp; IP 池组 / Lines &amp; IP Pools</h3>';
    $h .= '<p style="color:#666;font-size:12px">线路=独立维度：决定交付哪段公网 IP（线路→交付池组）+ 落地交换机。'
        . '池组只加<strong>原始母段</strong>（/25、/27… 任意混搭）+ 设交付掩码范围，切割<strong>全自动</strong>（不预切、释放不残碎）。</p>';

    // ---- 线路 ----
    $h .= '<h4>线路 / Lines</h4><table class="table table-condensed table-striped"><thead><tr>'
        . '<th>ID</th><th>名称</th><th>说明</th><th>落地交换机</th><th>启用</th><th>操作</th></tr></thead><tbody>';
    foreach ($lines as $l) {
        $h .= '<tr><td>#' . (int) $l->id . '</td>'
            . '<td><strong>' . htmlspecialchars((string) $l->name, ENT_QUOTES) . '</strong></td>'
            . '<td><small>' . htmlspecialchars((string) ($l->descr ?? ''), ENT_QUOTES) . '</small></td>'
            . '<td><small>' . htmlspecialchars($l->device_id ? ($devName[(int) $l->device_id] ?? ('#' . (int) $l->device_id)) : '—', ENT_QUOTES) . '</small></td>'
            . '<td>' . ((int) $l->enabled === 1 ? '✅' : '—') . '</td>'
            . '<td>' . owpprov_admin_mini_form($modulelink, 'line_delete', ['id' => (int) $l->id], '删除', 'btn-danger', '删除该线路？') . '</td></tr>';
    }
    if (!$lines) { $h .= '<tr><td colspan="6"><em>暂无线路。</em></td></tr>'; }
    $h .= '</tbody></table>';
    $vrpOpts = '<option value="">（落地交换机）</option>';
    foreach ($vrp as $i => $nm) { $vrpOpts .= '<option value="' . $i . '">' . htmlspecialchars($nm, ENT_QUOTES) . '</option>'; }
    $h .= '<details class="ipd-dev"><summary>＋ 新增线路 / Add Line</summary><form method="post" action="' . $url . '&action=line_add">' . $tf
        . '<input type="hidden" name="action" value="line_add"/><div class="ipd-grid">'
        . owpprov_field('name', '线路名 / Name', '', 'text', '如 HKBGP / HKBGP-CNBackBone')
        . owpprov_field('descr', '说明(可选)', '', 'text', '路由属性备注')
        . '<div class="ipd-f"><label>落地交换机 / Switch</label><select name="device_id" class="form-control">' . $vrpOpts . '</select></div>'
        . '</div><div style="margin-top:8px"><button class="btn btn-primary">新增线路</button></div></form></details>';

    // ---- 池组 ----
    $h .= '<h4 style="margin-top:14px">IP 池组 / Pool Groups</h4>';
    foreach (Pools::groups() as $g) {
        $blocks = Pools::blocks((int) $g->id);
        $lineNm = $g->line_id ? ('线路 ' . htmlspecialchars((string) (Lines::get((int) $g->line_id)?->name ?? ('#' . $g->line_id)), ENT_QUOTES)) : '—';
        $devNm  = $g->device_id ? htmlspecialchars($devName[(int) $g->device_id] ?? ('#' . (int) $g->device_id), ENT_QUOTES) : '—';
        $h .= '<details class="ipd-dev"><summary>#' . (int) $g->id . ' <strong>' . htmlspecialchars((string) $g->name, ENT_QUOTES) . '</strong> '
            . '<small style="color:#888">[' . htmlspecialchars((string) $g->purpose, ENT_QUOTES) . ' · /' . (int) $g->deliver_min . '~/' . (int) $g->deliver_max
            . ' · ' . $lineNm . ' · ' . $devNm . ' · 母段 ' . count($blocks) . ($g->enabled ? '' : ' · 停用') . ']</small></summary>';
        $h .= '<table class="table table-condensed"><tbody>';
        foreach ($blocks as $b) {
            $h .= '<tr><td><code>' . htmlspecialchars((string) $b->cidr, ENT_QUOTES) . '</code></td><td style="width:60px">'
                . owpprov_admin_mini_form($modulelink, 'poolblock_delete', ['id' => (int) $b->id], '删', 'btn-danger', '删除该母段？') . '</td></tr>';
        }
        if (!$blocks) { $h .= '<tr><td><em>未加母段——添加后才能从此组分配。</em></td></tr>'; }
        $h .= '</tbody></table>';
        $h .= '<form method="post" action="' . $url . '&action=poolblock_add" style="margin:6px 0">' . $tf
            . '<input type="hidden" name="action" value="poolblock_add"/><input type="hidden" name="group_id" value="' . (int) $g->id . '"/>'
            . '<input type="text" name="cidr" class="form-control input-sm" placeholder="原始母段，如 203.0.113.0/25" style="display:inline-block;width:240px"/> '
            . '<button class="btn btn-default btn-sm">＋ 加母段</button></form>';
        $h .= owpprov_admin_pool_form($modulelink, $g, $lines, $devName, $tf);
        $h .= '<div style="margin-top:6px">' . owpprov_admin_mini_form($modulelink, 'pool_delete', ['id' => (int) $g->id], '删除整个池组', 'btn-danger', '删除该池组及其全部母段？') . '</div>';
        $h .= '</details>';
    }
    $h .= '<details class="ipd-dev"><summary><strong>＋ 新增 IP 池组 / Add Pool Group</strong></summary>'
        . owpprov_admin_pool_form($modulelink, null, $lines, $devName, $tf) . '</details>';

    $h .= '</div>';
    return $h;
}

/** 池组 新增/编辑 表单（$g=null 为新增）。 */
function owpprov_admin_pool_form(string $modulelink, ?object $g, array $lines, array $devName, string $tf): string
{
    $url  = htmlspecialchars($modulelink, ENT_QUOTES);
    $act  = $g ? 'pool_save' : 'pool_add';
    $val  = static function (string $f, $d = '') use ($g) { return htmlspecialchars((string) ($g->{$f} ?? $d), ENT_QUOTES); };
    $psel = static function (string $o) use ($g) { return (string) ($g->purpose ?? 'delivery') === $o ? ' selected' : ''; };
    $lineOpts = '<option value="">（不绑线路 / 按设备）</option>';
    foreach ($lines as $l) {
        $s = ($g && (int) ($g->line_id ?? 0) === (int) $l->id) ? ' selected' : '';
        $lineOpts .= '<option value="' . (int) $l->id . '"' . $s . '>' . htmlspecialchars((string) $l->name, ENT_QUOTES) . '</option>';
    }
    $devOpts = '<option value="">（落地设备：交付=交换机 / vpn=ROS）</option>';
    foreach ($devName as $i => $nm) {
        $s = ($g && (int) ($g->device_id ?? 0) === (int) $i) ? ' selected' : '';
        $devOpts .= '<option value="' . (int) $i . '"' . $s . '>' . htmlspecialchars($nm, ENT_QUOTES) . '</option>';
    }
    $enChecked = (!$g || (int) ($g->enabled ?? 1) === 1) ? ' checked' : '';
    $h  = '<form method="post" action="' . $url . '&action=' . $act . '" style="margin-top:6px">' . $tf
        . '<input type="hidden" name="action" value="' . $act . '"/>';
    if ($g) { $h .= '<input type="hidden" name="id" value="' . (int) $g->id . '"/>'; }
    $h .= '<div class="ipd-grid">'
        . owpprov_field('name', '池组名 / Name', $val('name'), 'text', '如 HK 交付池')
        . '<div class="ipd-f"><label>用途 / Purpose</label><select name="purpose" class="form-control">'
            . '<option value="delivery"' . $psel('delivery') . '>delivery（交付公网段）</option>'
            . '<option value="vpn"' . $psel('vpn') . '>vpn（VPN 客户 /32）</option></select></div>'
        . '<div class="ipd-f"><label>线路 / Line（交付）</label><select name="line_id" class="form-control">' . $lineOpts . '</select></div>'
        . '<div class="ipd-f"><label>落地设备 / Device</label><select name="device_id" class="form-control">' . $devOpts . '</select></div>'
        . owpprov_field('deliver_min', '最大块掩码 / min len（如 26）', $val('deliver_min', '26'), 'text', '允许最大块=最小掩码长度')
        . owpprov_field('deliver_max', '最小块掩码 / max len（如 30）', $val('deliver_max', '30'), 'text', '允许最小块=最大掩码长度')
        . '<div class="ipd-f"><label>启用 / Enabled</label><div><label class="ipd-chk"><input type="checkbox" name="enabled" value="1"' . $enChecked . '> 启用</label></div></div>'
        . '</div><div style="margin-top:8px"><button class="btn btn-primary">' . ($g ? '保存池组' : '新增池组') . '</button></div></form>';
    return $h;
}

function owpprov_admin_styles(): string
{
    return '<style>
        .ipd-card{border:1px solid #e1e1e8;border-radius:6px;padding:14px 16px;margin:14px 0;background:#fff}
        .ipd-card h3{margin-top:0;border-bottom:1px solid #eee;padding-bottom:8px}
        .ipd-card table code{font-size:12px}
        details.ipd-dev{border:1px solid #eee;border-radius:5px;padding:8px 12px;margin:8px 0;background:#fafafa}
        details.ipd-dev>summary{cursor:pointer;font-weight:bold;color:#31708f}
        .ipd-grid{display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:8px 16px;margin-top:8px}
        .ipd-f label{display:block;color:#555;font-size:12px;margin-bottom:2px}
        .ipd-f .form-control{max-width:360px}
        .ipd-chk{font-weight:normal}
        .ipd-kind{margin:6px 0 10px;padding:6px 10px;border-left:3px solid #e1e1e8;background:#fcfcfc}
        .ipd-kind h5{margin:4px 0;font-weight:bold}
        .ipd-pool-row{padding:4px 0;border-bottom:1px dashed #eee}
        .ipd-pool-add{margin-top:6px}
        .ipd-bulkbar{margin:6px 0 2px;padding:3px 0}
        .ipd-bulk{vertical-align:middle;margin-right:2px}
        .ipd-card .input-sm{height:28px;font-size:12px}
        .ipd-free{color:#3c763d;font-size:11px;font-weight:bold}
        .ipd-occ{color:#a94442;font-weight:bold;text-decoration:underline}
        .ipd-src{display:inline-block;font-size:10px;color:#888;border:1px solid #ddd;border-radius:3px;padding:0 4px}
        .ipd-src-auto{color:#31708f;border-color:#bce8f1}
        .ipd-formlabel{display:inline-block;min-width:70px;color:#555;font-size:12px}
        .ipd-card>h3,.ipd-kind>h5{user-select:none}
    </style>'
    // P6：层级可折叠——三大区(.ipd-card h3) + 资源类(.ipd-kind h5) 默认折叠、点标题展开，状态记 localStorage。
    . "<script>document.addEventListener('DOMContentLoaded',function(){"
    . "var LS=window.localStorage;function gs(k,d){try{var v=LS.getItem(k);return v===null?d:v==='1';}catch(e){return d;}}"
    . "function ss(k,v){try{LS.setItem(k,v?'1':'0');}catch(e){}}"
    . "function mk(head,key,def){var rest=[],n=head.nextSibling;while(n){var x=n.nextSibling;rest.push(n);n=x;}"
    . "var body=document.createElement('div');rest.forEach(function(t){body.appendChild(t);});head.parentNode.insertBefore(body,head.nextSibling);"
    . "var open=gs(key,def),c=document.createElement('span');c.style.cssText='cursor:pointer;margin-right:6px;color:#999;font-size:11px';head.insertBefore(c,head.firstChild);head.style.cursor='pointer';"
    . "function ap(){body.style.display=open?'':'none';c.textContent=open?'▾':'▸';}"
    . "head.addEventListener('click',function(e){var t=e.target.tagName;if(t==='A'||t==='INPUT'||t==='BUTTON'||t==='SELECT')return;open=!open;ss(key,open);ap();});ap();}"
    . "[].slice.call(document.querySelectorAll('.ipd-card')).forEach(function(cd){var h=cd.querySelector(':scope>h3');if(h)mk(h,'owpc:'+(h.textContent||'').slice(0,40),false);});"
    . "[].slice.call(document.querySelectorAll('.ipd-kind')).forEach(function(kc){var h=kc.querySelector(':scope>h5');if(h)mk(h,'owpk:'+(kc.textContent||'').slice(0,50),false);});"
    . "});</script>";
}
