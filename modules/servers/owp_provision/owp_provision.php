<?php
/**
 * IP-Delivery — WHMCS Server / Provisioning Module
 * ============================================================================
 * 客户在 WHMCS 下单后，自动 SSH 到接入交换机 接入交换机，开通「XC（VLAN+端口+
 * Vlanif/PTP）或 GRE（隧道）方式交付的一段公网 IP」，并用 traffic-policy 限速；
 * 暂停/恢复/销户时反向拆除并回收资源（VLAN/PTP/IP/端口/隧道）。
 *
 * 🔴 硬约束：
 *   - 只动 接入交换机（vlan/port/Vlanif/Tunnel/LoopBack/ip route-static/traffic-policy/save）。
 *     **绝不**碰 BGP network / route-policy / AS / 上游路由器。交付段是已宣告聚合内的更具体段
 *     （人工预置 = 前提）。
 *   - 全自动（无「管理员点确认」半自动），但支持 dry-run（只渲染+记日志，不触设备）。
 *   - 设备无 commit：每次改完 save + 应答 Y（否则重启丢配置）。
 *   - 幂等：display 预检后只补缺失；重复开通不报错、不重复。
 *   - 下发后校验回读；失败尽力回滚已下发部分 + 释放分配 + 返回可读错误。
 *   - 零硬编码凭据：连接配置全部走 addon 加密配置 + 服务器上的 key 文件。
 *
 * 返回值约定：生命周期函数成功 `return 'success'`，否则返回**人能看懂的错误串**。
 * 每个函数 try/catch + logModuleCall（脱敏 $params 再记）。
 *
 * 🟡 编码不确定处：$params 的 configoptions/customfields **按名索引**（见 01 §A.2）；
 *    若线上呈现与假设不符，先在函数入口 logModuleCall(__FUNCTION__, var_export($params,true))
 *    落一遍真实结构再调键名。下方 owpprov_pluck*() 已做多名兜底。
 *
 * @package OwpProvision
 * @target  WHMCS 9.0.4 / PHP 8.3
 */

use WHMCS\Database\Capsule;
use OwpProvision\Schema;
use OwpProvision\Config;
use OwpProvision\Devices;
use OwpProvision\Servers;
use OwpProvision\Ipam;
use OwpProvision\Templates;
use OwpProvision\Connection;
use OwpProvision\Types;
use OwpProvision\Orchestrator;
use OwpProvision\Drivers\VrpDriver;
use OwpProvision\Drivers\RosDriver;
use OwpProvision\Drivers\DracDriver;

if (!defined('WHMCS')) {
    die('Access Denied');
}

// ---- 共用 lib 加载（server 与 addon 共用同一套；addon 也 require 这个目录） -------
require_once __DIR__ . '/lib/Schema.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Devices.php';
require_once __DIR__ . '/lib/Servers.php';
require_once __DIR__ . '/lib/Types.php';
require_once __DIR__ . '/lib/Ipam.php';
require_once __DIR__ . '/lib/Resources.php';
require_once __DIR__ . '/lib/Templates.php';
require_once __DIR__ . '/lib/Connection.php';
require_once __DIR__ . '/lib/Orchestrator.php';
require_once __DIR__ . '/lib/Drivers/DriverInterface.php';
require_once __DIR__ . '/lib/Drivers/VrpDriver.php';
require_once __DIR__ . '/lib/Drivers/RosDriver.php';
require_once __DIR__ . '/lib/Drivers/DracDriver.php';

// ============================================================================
// MetaData / ConfigOptions
// ============================================================================

/**
 * 模块元数据。连接配置放 addon（不绑 Server 条目），故 RequiresServer=false。
 * @return array
 */
function owp_provision_MetaData()
{
    return [
        'DisplayName'             => 'IP Delivery',
        'APIVersion'             => '1.1',
        'RequiresServer'         => false, // 连接配置在 addon；不需要 WHMCS Server 条目
        'DefaultNonSSLPort'      => '',
        'DefaultSSLPort'         => '',
        'ServiceSingleSignOnLabel' => '',
    ];
}

/**
 * 模块级 ConfigOptions（产品 Module Settings 里设；configoption1..N）。
 * 顺序很重要：WHMCS 以 configoption1..N 传入。这里定义 4 个：
 *   1 defaultBw  2 defaultPrefix  3 namingPrefix  4 dryRun
 * @return array
 */
function owp_provision_ConfigOptions()
{
    return [
        // configoption1
        'defaultBandwidth' => [
            'FriendlyName' => 'Default Bandwidth 默认带宽',
            'Type'         => 'dropdown',
            'Options'      => '100M,200M,500M,1G',
            'Default'      => '100M',
            'Description'  => '被 Configurable Options 的 bandwidth 覆盖；都没有时用此值换算 traffic-policy CIR。',
        ],
        // configoption2
        'defaultPrefixSize' => [
            'FriendlyName' => 'Default Prefix Size 默认交付掩码',
            'Type'         => 'dropdown',
            'Options'      => '/32,/30,/29,/28',
            'Default'      => '/32',
            'Description'  => '被 Configurable Options 的 prefix_size 覆盖。',
        ],
        // configoption3
        'namingPrefix' => [
            'FriendlyName' => 'Naming Prefix 命名前缀',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 'WHMCS',
            'Description'  => '生成设备 description 用：{prefix}-{serviceid}-{clientname}。',
        ],
        // configoption4
        'dryRun' => [
            'FriendlyName' => 'Dry Run 仅渲染不下发',
            'Type'         => 'yesno',
            'Description'  => '勾选后：只生成命令块并写日志，不真连设备（内部测试用）。也可在 addon 配置里开全局 dry-run。',
        ],
        // configoption5
        'serviceModel' => [
            'FriendlyName' => 'Service Model 服务形态',
            'Type'         => 'dropdown',
            'Options'      => 'ip_transit,server',
            'Default'      => 'ip_transit',
            'Description'  => 'ip_transit = 纯 IP 交付（XC/GRE，客户自带设备）；server = 租赁/托管服务器（选服务器→绑端口+发IP+开 IPMI VPN）。',
        ],
    ];
}

// ============================================================================
// 生命周期：CreateAccount / Suspend / Unsuspend / Terminate / ChangePackage
// ============================================================================

/**
 * 开通（核心）。流程：读参数 → 校验 → 事务分配 → 连接 → 幂等预检 → 渲染 →
 * 下发(save+Y) → 校验回读（失败回滚+释放）→ 回写 custom fields → 'success'。
 *
 * @param array $params
 * @return string 'success' | 错误串
 */
function owp_provision_CreateAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        if ($serviceId <= 0) {
            return 'Error: 缺少 serviceid（无法分配/记录）。请先 logModuleCall 检查 $params。';
        }

        // 服务形态分流：server=租赁/托管（绑服务器+发IP+开 IPMI VPN）；否则走纯 IP 交付（XC/GRE）。
        if (strtolower((string) ($params['configoption5'] ?? 'ip_transit')) === 'server') {
            return owpprov_create_server($params);
        }

        // 1) 读参数
        $deliveryType = strtolower(owpprov_pluck_co($params, ['delivery_type', 'Delivery Type'], owpprov_default_delivery($params)));
        $bandwidth    = owpprov_pluck_co($params, ['bandwidth', 'Bandwidth'], (string) ($params['configoption1'] ?? '100M'));
        $prefixSize   = owpprov_pluck_co($params, ['prefix_size', 'Prefix Size'], (string) ($params['configoption2'] ?? '/32'));
        $namingPrefix = (string) ($params['configoption3'] ?? 'WHMCS');
        $remoteIp     = trim(owpprov_pluck_cf($params, ['Remote Endpoint IP', 'Remote IP'], ''));
        $wantPort     = trim(owpprov_pluck_cf($params, ['XC Port', 'Port'], ''));
        $nodeSel      = owpprov_pluck_co($params, ['node', 'Node', 'device', 'Device', '节点'], '');
        $clientName   = owpprov_client_name($params);
        $custTag      = Templates::custTag($namingPrefix, $serviceId, $clientName);

        // 节点（设备）确定：下单所选 → 单设备免选默认。无法确定/未启用即报错。
        $deviceId = owpprov_resolve_device($params, null, $nodeSel);
        if ($deviceId <= 0) {
            return 'Error: 无法确定交付节点。请在产品的 Configurable Option「node」选择一个设备，'
                . '或在 addon「设备」页启用唯一设备（单设备时免选）。';
        }
        if (!Devices::isEnabled($deviceId)) {
            return 'Error: 所选节点（设备 #' . $deviceId . '）不存在或已停用，请重新选择。';
        }

        // 交付类型走注册表：必须已定义且启用；前端下单还要 frontend 开放（admin 后台手动 Create 不受此限）。
        $typeDef = Types::get($deliveryType);
        if ($typeDef === null || !Types::isEnabled($deliveryType)) {
            return 'Error: 交付类型「' . $deliveryType . '」未定义或未启用。请检查 Configurable Option「delivery_type」与 addon「启用类型」配置。';
        }
        $isAdminCtx = !empty($_SESSION['adminid']); // 管理员后台手动 Create → 允许开通未开放前端的类型（如测试 GRE）
        if (!$isAdminCtx && !Types::isFrontend($deliveryType)) {
            return 'Error: 交付类型「' . strtoupper($deliveryType) . '」暂未开放下单。';
        }

        // 2) 校验
        if ($deliveryType === 'gre') {
            if ($remoteIp === '' || !Ipam::isPublicIpv4($remoteIp)) {
                return 'Error: GRE 交付必须在自定义字段「Remote Endpoint IP」填写合法的公网 IPv4 对端地址。';
            }
        }
        // 带宽必须能换算（提前失败，别等到下发）
        try {
            Templates::bandwidthToCirKbps($bandwidth);
        } catch (\Throwable $e) {
            return 'Error: 带宽档无法换算 CIR：' . $e->getMessage();
        }

        // 3)–9) 取全局锁串行执行（分配→连接→下发→校验→回写）；失败回滚+释放，每步落 oplog。
        return Orchestrator::withLock(function () use ($params, $serviceId, $deliveryType, $bandwidth, $prefixSize, $remoteIp, $wantPort, $custTag, $deviceId) {
            // 3) 事务分配资源（从所选设备的资源池分配，写 allocation.device_id）
            try {
                if ($deliveryType === 'xc') {
                    $alloc = Ipam::allocateXc($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $wantPort !== '' ? $wantPort : null);
                } else {
                    $alloc = Ipam::allocateGre($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $remoteIp);
                }
            } catch (\Throwable $e) {
                Orchestrator::log($serviceId, 'create', 'allocate', $deviceId, 'failed', '', $e->getMessage());
                owpprov_log(__FUNCTION__, $params, '', '分配失败：' . $e->getMessage());
                return 'Error: 资源分配失败：' . $e->getMessage();
            }
            $deviceId = (int) ($alloc['device_id'] ?? $deviceId); // 幂等复用旧分配时以记录为准
            Orchestrator::log($serviceId, 'create', 'allocate', $deviceId, 'ok', '', json_encode(
                ['type' => $alloc['delivery_type'] ?? '', 'prefix' => $alloc['prefix'] ?? '', 'vlan' => $alloc['vlan_id'] ?? null, 'port' => $alloc['port'] ?? null],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            // 4) 驱动（按本服务设备；dry-run 时不触设备）
            $drv   = owpprov_vrp($params, $deviceId);
            $isDry = $drv->isDryRun();

            // 5) 幂等预检（非 dry-run 才真读；失败不致命）
            if (!$isDry) {
                try {
                    $pre = $drv->runDisplay(array_values(Templates::verifyCommands($alloc)));
                    owpprov_log(__FUNCTION__ . ':precheck', $params, '', $pre);
                } catch (\Throwable $e) {
                    owpprov_log(__FUNCTION__ . ':precheck', $params, '', '预检读失败（忽略）：' . $e->getMessage());
                }
            }

            // 6–7) 渲染 + 下发（VrpDriver 按类型分发，含 save+Y；dry-run 只记日志）
            $res = $drv->provision($alloc, $custTag);
            logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $res['output'], $res['block']);

            if ($res['dryrun']) {
                Orchestrator::log($serviceId, 'create', 'vrp.provision', $deviceId, 'dryrun', '', '(dry-run，仅渲染)');
                owpprov_writeback_customfields($params, $alloc);
                return 'success';
            }
            if (!$res['ok']) {
                Orchestrator::log($serviceId, 'create', 'vrp.provision', $deviceId, 'failed', '', (string) $res['error']);
                owpprov_rollback_create($drv, $alloc, $serviceId); // 尽力拆除已下发部分 + 释放分配
                Orchestrator::log($serviceId, 'create', 'rollback', $deviceId, 'rollback', '', '已尝试拆除+释放分配');
                return 'Error: 下发失败，已尝试回滚并释放分配：' . $res['error'];
            }
            Orchestrator::log($serviceId, 'create', 'vrp.provision', $deviceId, 'ok', '', 'save 成功');

            // 8) 校验回读（顾问性，不作为回滚依据——客户侧多半未就绪）
            $verify = $drv->verifyDelivery($alloc);
            Orchestrator::log($serviceId, 'create', 'verify', $deviceId, $verify['ok'] ? 'ok' : 'failed', '', $verify['ok'] ? 'liveness OK' : (string) $verify['error']);
            if (!$verify['ok']) {
                logModuleCall('owp_provision', __FUNCTION__ . ':verify-advisory',
                    ['serviceid' => $serviceId], (string) ($verify['detail'] ?? ''),
                    '已下发并保存；liveness 暂未通过（通常因客户侧未就绪，非错误）：' . $verify['error']);
                if (function_exists('logActivity')) {
                    logActivity('[OWP Provision] 服务 #' . $serviceId . ' 配置已下发并保存；'
                        . 'liveness 暂未通过（多因客户侧未就绪，可稍后 Verify 复检）：' . $verify['error']);
                }
            }

            // 9) 回写 custom fields + 活动日志
            owpprov_writeback_customfields($params, $alloc);
            if (function_exists('logActivity')) {
                logActivity('[OWP Provision] 服务 #' . $serviceId . ' 已开通（' . strtoupper($deliveryType)
                    . '，prefix=' . ($alloc['prefix'] ?? '') . '）。');
            }
            return 'success';
        });

    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 暂停（可逆）：撤客户段静态路由（停对外可达，保留 VLAN/接口/Tunnel 骨架）→ save。
 * @return string
 */
function owp_provision_SuspendAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $alloc = owpprov_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }

        return Orchestrator::withLock(function () use ($params, $serviceId, $alloc) {
            $devId = owpprov_alloc_device($alloc);
            $drv   = owpprov_vrp($params, $devId);
            $res   = $drv->suspend((array) $alloc);
            logModuleCall('owp_provision', 'SuspendAccount', owpprov_safe_params($params), $res['output'], $res['block']);
            if (!$res['dryrun'] && !$res['ok']) {
                Orchestrator::log($serviceId, 'suspend', 'vrp.suspend', $devId, 'failed', '', (string) $res['error']);
                return 'Error: 暂停下发失败：' . $res['error'];
            }
            Ipam::setStatus($serviceId, 'suspended');
            Orchestrator::log($serviceId, 'suspend', 'vrp.suspend', $devId, $res['dryrun'] ? 'dryrun' : 'ok', '', '撤客户段静态路由');
            return 'success';
        });
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 恢复：重下客户段静态路由 → save。
 * @return string
 */
function owp_provision_UnsuspendAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $alloc = owpprov_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }

        return Orchestrator::withLock(function () use ($params, $serviceId, $alloc) {
            $devId = owpprov_alloc_device($alloc);
            $drv   = owpprov_vrp($params, $devId);
            $res   = $drv->unsuspend((array) $alloc);
            logModuleCall('owp_provision', 'UnsuspendAccount', owpprov_safe_params($params), $res['output'], $res['block']);
            if (!$res['dryrun'] && !$res['ok']) {
                Orchestrator::log($serviceId, 'unsuspend', 'vrp.unsuspend', $devId, 'failed', '', (string) $res['error']);
                return 'Error: 恢复下发失败：' . $res['error'];
            }
            Ipam::setStatus($serviceId, 'active');
            Orchestrator::log($serviceId, 'unsuspend', 'vrp.unsuspend', $devId, $res['dryrun'] ? 'dryrun' : 'ok', '', '重下客户段静态路由');
            return 'success';
        });
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 销户：反向 undo 全部（路由→解绑policy→删接口→删policy/behavior/classifier→删VLAN）→ save →
 * 释放分配 → 校验已清除。teardown 顺序见拆除模板。
 * @return string
 */
function owp_provision_TerminateAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $allocObj = Ipam::getAllocation($serviceId);
        if (!$allocObj) {
            // 无分配记录：可能从未开通或已销户。幂等：直接当成功（无残留可拆）。
            return 'success';
        }
        $alloc = (array) $allocObj;

        return Orchestrator::withLock(function () use ($params, $serviceId, $alloc) {
            $devId = owpprov_alloc_device($alloc);
            $drv   = owpprov_vrp($params, $devId);
            $res   = $drv->teardown($alloc);
            logModuleCall('owp_provision', 'TerminateAccount', owpprov_safe_params($params), $res['output'], $res['block']);

            if ($res['dryrun']) {
                // dry-run 也走纯 DB 终态（不触设备），与真实路径终态一致：撤 VPN/iDRAC 仅记 dryrun，
                // 但 Servers::releaseByService 是纯 DB（不触设备）→ 必须真执行，否则服务器在 dry-run 销户后仍 rented。
                $rosId = (int) ($alloc['vpn_device_id'] ?? 0);
                $srv   = Servers::byService($serviceId);
                if ($rosId > 0 && $srv && (string) ($srv->ipmi_kind ?? '') === 'idrac') {
                    Orchestrator::log($serviceId, 'terminate', 'drac.user_delete', $rosId, 'dryrun', '', '(dry-run) 跳过删 iDRAC 子账号');
                }
                if ($rosId > 0) {
                    Orchestrator::log($serviceId, 'terminate', 'ros.vpn_revoke', $rosId, 'dryrun', '', '(dry-run) 跳过撤 VPN + 管理 DNAT');
                }
                if ($srv) {
                    Servers::releaseByService($serviceId);
                    Orchestrator::log($serviceId, 'terminate', 'server.release', null, 'dryrun', '', '(dry-run) 服务器回库存(free)');
                }
                Ipam::release($serviceId);
                Orchestrator::log($serviceId, 'terminate', 'vrp.teardown', $devId, 'dryrun', '', '(dry-run) 已释放分配');
                return 'success';
            }
            if (!$res['ok']) {
                // 拆除失败：不释放分配（避免「记录回池但设备仍有残留」），返回错误让 staff 介入。
                Orchestrator::log($serviceId, 'terminate', 'vrp.teardown', $devId, 'failed', '', (string) $res['error']);
                return 'Error: 拆除下发失败（资源未回池，待人工核查设备残留）：' . $res['error'];
            }
            Orchestrator::log($serviceId, 'terminate', 'vrp.teardown', $devId, 'ok', '', 'undo+save 成功');

            // 校验已清除（best-effort）：接口/路由应不再命中
            $verify = $drv->verifyTeardown($alloc);
            if (!$verify['ok']) {
                // 设备可能仍有残留：保留分配记录、返回错误（不静默回池）。
                Orchestrator::log($serviceId, 'terminate', 'verify', $devId, 'failed', '', (string) $verify['error']);
                return 'Error: 拆除后仍检测到残留，请人工核查（资源暂不回池）：' . $verify['error'];
            }

            // 服务器形态：删 iDRAC 客户号 → 撤 IPMI VPN（ROS）→ 释放服务器库存
            $rosId = (int) ($alloc['vpn_device_id'] ?? 0);
            $srv   = Servers::byService($serviceId);
            // iDRAC 删客户子账号（经临时 DNAT；非致命）
            if ($rosId > 0 && $srv && (string) ($srv->ipmi_kind ?? '') === 'idrac' && !empty($srv->ipmi_ip)) {
                $iu   = trim((string) ($srv->ipmi_user ?? ''));
                $ip   = Config::serverSecret((int) $srv->id, 'ipmi_pass');
                $msrc = Config::get('mgmtSrcIp', '');
                $vu   = trim((string) ($alloc['vpn_user'] ?? ''));
                if ($iu !== '' && $ip !== '' && $msrc !== '' && $vu !== '') {
                    $rosDev  = Devices::get($rosId);
                    $rosHost = $rosDev ? (string) $rosDev->device_host : '';
                    $pubPort = (int) Config::get('dnatPortBase', '20000') + $serviceId;
                    $ros2    = owpprov_ros($params, $rosId);
                    try {
                        $ros2->dnatOpen($serviceId, (string) $srv->ipmi_ip, 443, $msrc, $pubPort);
                        (new DracDriver('https://' . $rosHost . ':' . $pubPort, $iu, $ip, Config::isDryRun($params), (string) $srv->ipmi_ip))->deleteUser($vu);
                        Orchestrator::log($serviceId, 'terminate', 'drac.user_delete', $rosId, 'ok', '', $vu);
                    } catch (\Throwable $e) {
                        Orchestrator::log($serviceId, 'terminate', 'drac.user_delete', $rosId, 'failed', '', $e->getMessage());
                    } finally {
                        try { $ros2->dnatClose($serviceId); } catch (\Throwable $e) {}
                    }
                }
            }
            if ($rosId > 0) {
                try {
                    $ros = owpprov_ros($params, $rosId);
                    $ros->vpnRevoke($serviceId);
                    $ros->dnatClose($serviceId);
                    Orchestrator::log($serviceId, 'terminate', 'ros.vpn_revoke', $rosId, 'ok', '', '已撤 VPN + 管理 DNAT');
                } catch (\Throwable $e) {
                    Orchestrator::log($serviceId, 'terminate', 'ros.vpn_revoke', $rosId, 'failed', '', $e->getMessage());
                }
            }
            if (Servers::byService($serviceId)) {
                Servers::releaseByService($serviceId);
                Orchestrator::log($serviceId, 'terminate', 'server.release', null, 'ok', '', '服务器回库存(free)');
            }

            Ipam::release($serviceId);
            Orchestrator::log($serviceId, 'terminate', 'release', $devId, 'ok', '', '校验无残留，资源回池');
            if (function_exists('logActivity')) {
                logActivity('[OWP Provision] 服务 #' . $serviceId . ' 已销户拆除并回收资源。');
            }
            return 'success';
        });
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 改套餐：默认只支持改带宽档（重下 traffic-policy 的 car 值，幂等覆盖）。
 * 改掩码涉及重切前缀+改路由，风险高，暂不自动支持（返回提示，需人工/重开）。
 * @return string
 */
function owp_provision_ChangePackage(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $alloc = owpprov_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }
        $alloc = (array) $alloc;

        $newBw   = owpprov_pluck_co($params, ['bandwidth', 'Bandwidth'], (string) ($alloc['bandwidth'] ?? ''));
        $newSize = owpprov_pluck_co($params, ['prefix_size', 'Prefix Size'], '');

        // 掩码变化 → 不自动处理（保护性）
        if ($newSize !== '' && $alloc['prefix']) {
            $curLen = (int) explode('/', (string) $alloc['prefix'])[1];
            $wantLen = Ipam::normalizeMaskLen($newSize);
            if ($wantLen !== $curLen) {
                return 'Error: 改交付掩码（/' . $curLen . '→/' . $wantLen . '）需重新分配前缀与改路由，'
                    . '本模块未自动支持。请人工或销户后重新开通。';
            }
        }

        if ($newBw === '' ) {
            return 'success'; // 没有可改项
        }
        try {
            Templates::bandwidthToCirKbps($newBw);
        } catch (\Throwable $e) {
            return 'Error: 新带宽档无法换算 CIR：' . $e->getMessage();
        }

        // 更新分配记录里的带宽（DB 总是更新；设备命令按交付类型生成）。
        $alloc['bandwidth'] = $newBw;
        Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)
            ->update(['bandwidth' => $newBw, 'updated_at' => date('Y-m-d H:i:s')]);

        // XC：重下端口 qos lr 的 cir；隧道（GRE 等）本轮设备不限速 → changeBandwidth 返回空 → 不触设备（仅记录）。
        $bwLines = Templates::changeBandwidth($alloc);
        if (empty($bwLines)) {
            return 'success';
        }
        return Orchestrator::withLock(function () use ($params, $serviceId, $alloc) {
            $devId = owpprov_alloc_device($alloc);
            $drv   = owpprov_vrp($params, $devId);
            $res   = $drv->changeBandwidth($alloc); // XC 重下端口 qos lr；隧道返回空 = no-op
            logModuleCall('owp_provision', 'ChangePackage', owpprov_safe_params($params), $res['output'], $res['block']);
            if (!$res['dryrun'] && !$res['ok']) {
                Orchestrator::log($serviceId, 'change', 'vrp.changeBandwidth', $devId, 'failed', '', (string) $res['error']);
                return 'Error: 改带宽下发失败：' . $res['error'];
            }
            Orchestrator::log($serviceId, 'change', 'vrp.changeBandwidth', $devId, $res['dryrun'] ? 'dryrun' : 'ok', '', '端口 qos lr 已更新');
            return 'success';
        });
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

// ============================================================================
// 蓝图：服务器租赁 / 托管（serviceModel=server）
// ============================================================================

/**
 * 服务器开通蓝图：绑空闲服务器 → 在它的交换机端口发 IP（XC 式）→ 经其 ROS 开 IPMI VPN。
 * 全局锁串行；每步 oplog；任一步失败逐步回滚（撤 VPN / 拆交换机 / 回收分配 / 释放服务器）。
 * 交付即「网络通 + VPN 可达 IPMI」，OS 由客户自行经 IPMI 安装（iDRAC 自动建号见 P4）。
 *
 * @return string 'success' | 错误串
 */
function owpprov_create_server(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        if ($serviceId <= 0) {
            return 'Error: 缺少 serviceid。';
        }
        // 读参数
        $bandwidth    = owpprov_pluck_co($params, ['bandwidth', 'Bandwidth'], (string) ($params['configoption1'] ?? '100M'));
        $prefixSize   = owpprov_pluck_co($params, ['prefix_size', 'Prefix Size'], (string) ($params['configoption2'] ?? '/29'));
        $namingPrefix = (string) ($params['configoption3'] ?? 'WHMCS');
        $line         = owpprov_pluck_co($params, ['line', 'Line', '线路'], '');
        $serverSel    = owpprov_pluck_co($params, ['server', 'Server', '服务器'], '');
        $custTag      = Templates::custTag($namingPrefix, $serviceId, owpprov_client_name($params));
        $wantServerId = ctype_digit($serverSel) ? (int) $serverSel : 0;
        // VPN 凭据 = WHMCS 服务 username/password；Other 类产品常无 username → 自动生成并存回服务（幂等，客户可一次性查看）
        [$vpnUser, $vpnPass] = owpprov_ensure_service_credentials($params);

        try {
            Templates::bandwidthToCirKbps($bandwidth);
        } catch (\Throwable $e) {
            return 'Error: 带宽档无法换算 CIR：' . $e->getMessage();
        }

        return Orchestrator::withLock(function () use ($params, $serviceId, $bandwidth, $prefixSize, $custTag, $line, $wantServerId, $vpnUser, $vpnPass) {
            // 1) 绑定空闲服务器（原子）
            try {
                $srv = Servers::bindFree($serviceId, $line, $wantServerId);
            } catch (\Throwable $e) {
                Orchestrator::log($serviceId, 'create_server', 'bind', null, 'failed', '', $e->getMessage());
                return 'Error: 绑定服务器失败：' . $e->getMessage();
            }
            $deviceId = (int) $srv->device_id;
            $port     = (string) $srv->port;
            Orchestrator::log($serviceId, 'create_server', 'bind', $deviceId, 'ok', '', json_encode(
                ['server' => $srv->name ?? '', 'port' => $port, 'ipmi' => $srv->ipmi_ip ?? ''],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            // 2) 在该服务器的固定端口分配并发 IP（server 直连模型：vlan + Vlanif 当网关 + port access + qos lr，无 PTP/route）
            try {
                $alloc = Ipam::allocateServer($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $port);
            } catch (\Throwable $e) {
                Orchestrator::log($serviceId, 'create_server', 'allocate', $deviceId, 'failed', '', $e->getMessage());
                Servers::releaseByService($serviceId);
                return 'Error: 资源分配失败：' . $e->getMessage();
            }
            $deviceId = (int) ($alloc['device_id'] ?? $deviceId);
            Orchestrator::log($serviceId, 'create_server', 'allocate', $deviceId, 'ok', '', (string) ($alloc['prefix'] ?? ''));

            // 3) 交换机下发
            $drv = owpprov_vrp($params, $deviceId);
            $res = $drv->provision($alloc, $custTag);
            logModuleCall('owp_provision', 'CreateServer', owpprov_safe_params($params), $res['output'], $res['block']);
            if (!$res['dryrun'] && !$res['ok']) {
                Orchestrator::log($serviceId, 'create_server', 'vrp.provision', $deviceId, 'failed', '', (string) $res['error']);
                owpprov_rollback_create($drv, $alloc, $serviceId);
                Servers::releaseByService($serviceId);
                return 'Error: 交换机下发失败，已回滚：' . $res['error'];
            }
            Orchestrator::log($serviceId, 'create_server', 'vrp.provision', $deviceId, $res['dryrun'] ? 'dryrun' : 'ok', '', '');

            // 4) 经 ROS 开 IPMI VPN（一条 ppp secret = L2TP/PPTP/SSTP/OpenVPN + 可选 IKEv2）
            $rosId = (int) ($srv->vpn_device_id ?? 0);
            if ($rosId > 0 && !empty($srv->ipmi_ip) && $vpnUser !== '') {
                try {
                    $vpnIp = Ipam::pickOrReuseVpnIp($rosId, $serviceId); // 重跑复用已分配地址，避免泄漏/不一致
                    owpprov_ros($params, $rosId)->vpnGrant($serviceId, $vpnIp, (string) $srv->ipmi_ip, $vpnUser, $vpnPass);
                    Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)->update([
                        'vpn_device_id' => $rosId,
                        'vpn_ip'        => $vpnIp,
                        'vpn_target'    => (string) $srv->ipmi_ip,
                        'vpn_user'      => $vpnUser,
                        'vpn_pass_enc'  => Config::encrypt($vpnPass),
                        'updated_at'    => date('Y-m-d H:i:s'),
                    ]);
                    Orchestrator::log($serviceId, 'create_server', 'ros.vpn', $rosId, $res['dryrun'] ? 'dryrun' : 'ok', '', $vpnIp . ' → ' . $srv->ipmi_ip);
                } catch (\Throwable $e) {
                    Orchestrator::log($serviceId, 'create_server', 'ros.vpn', $rosId, 'failed', '', $e->getMessage());
                    try { owpprov_ros($params, $rosId)->vpnRevoke($serviceId); } catch (\Throwable $re) {}
                    owpprov_rollback_create($drv, $alloc, $serviceId);
                    Servers::releaseByService($serviceId);
                    return 'Error: IPMI VPN 开通失败，已回滚：' . $e->getMessage();
                }
            } else {
                Orchestrator::log($serviceId, 'create_server', 'ros.vpn', null, 'skipped', '', '未配置 ROS/IPMI 或无服务凭据，跳过 VPN');
            }

            // 4b) iDRAC：经 ROS 临时 DNAT 建最小权限客户子账号（非致命：失败仅告警，网络+VPN 已成；通道用后即撤）
            $mgmtSrc  = Config::get('mgmtSrcIp', '');
            $portBase = (int) Config::get('dnatPortBase', '20000');
            $ipmiUser = trim((string) ($srv->ipmi_user ?? ''));
            $ipmiPass = isset($srv->id) ? Config::serverSecret((int) $srv->id, 'ipmi_pass') : '';
            if ($rosId > 0 && (string) ($srv->ipmi_kind ?? '') === 'idrac'
                && !empty($srv->ipmi_ip) && $ipmiUser !== '' && $ipmiPass !== '' && $mgmtSrc !== '' && $vpnUser !== '') {
                $rosDev  = Devices::get($rosId);
                $rosHost = $rosDev ? (string) $rosDev->device_host : '';
                $pubPort = $portBase + $serviceId;
                $ros     = owpprov_ros($params, $rosId);
                try {
                    $ros->dnatOpen($serviceId, (string) $srv->ipmi_ip, 443, $mgmtSrc, $pubPort);
                    // Host 头 = iDRAC 真实地址（DELL iDRAC 校验 Host；URL 仍走 DNAT 前端）。
                    $drac = new DracDriver('https://' . $rosHost . ':' . $pubPort, $ipmiUser, $ipmiPass, Config::isDryRun($params), (string) $srv->ipmi_ip);
                    $du   = $drac->createUser($vpnUser, $vpnPass, 'Operator');
                    Orchestrator::log($serviceId, 'create_server', 'drac.user', $rosId,
                        !empty($du['ok']) ? (!empty($du['dryRun']) ? 'dryrun' : 'ok') : 'failed', '',
                        !empty($du['ok']) ? ('slot ' . ($du['slot'] ?? '')) : (string) ($du['error'] ?? ''));
                } catch (\Throwable $e) {
                    Orchestrator::log($serviceId, 'create_server', 'drac.user', $rosId, 'failed', '', $e->getMessage());
                } finally {
                    try { $ros->dnatClose($serviceId); } catch (\Throwable $e) {}
                }
            }

            // 5) 回写 + 活动日志
            owpprov_writeback_customfields($params, $alloc);
            owpprov_set_customfield($params, 'Server', (string) ($srv->name ?? ''));
            if (function_exists('logActivity')) {
                logActivity('[OWP Provision] 服务 #' . $serviceId . ' 服务器开通（' . ($srv->name ?? '') . '，prefix=' . ($alloc['prefix'] ?? '') . '）。');
            }
            return 'success';
        });
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'owpprov_create_server', owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

// ============================================================================
// ClientArea（GRE 改对端）
// ============================================================================

/**
 * 客户区：GRE 服务展示交付段/PTP/对端/隧道状态 + 「改对端 IP」表单。
 * 提交（POST ipd_action=change_remote）→ 限频校验 → 改 Tunnel destination → save → 校验。
 *
 * @param array $params
 * @return array{templatefile:string, templateVariables:array}|string
 */
function owp_provision_ClientArea(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);

    // 自包含 CSRF nonce：先取上次渲染存入会话的旧值（用于校验本次 POST），再生成新值供本次表单。
    $ipdOldToken = (string) ($_SESSION['ipd_csrf_ca'] ?? '');
    $ipdNewToken = bin2hex(random_bytes(16));
    $_SESSION['ipd_csrf_ca'] = $ipdNewToken;

    $vars = [
        'serviceid'    => $serviceId,
        'deliveryType' => '',
        'prefix'       => '',
        'ptpOur'       => '',
        'ptpPeer'      => '',
        'loopback'     => '',
        'tunnelId'     => '',
        'remoteIp'     => '',
        'tunnelState'  => '(未查询)',
        'message'      => '',
        'error'        => '',
        'configHint'   => '',
        'cooldownMins' => 10,
        'ipd_token'    => $ipdNewToken,   // 客户区 CSRF nonce（模板隐藏字段用）
        'modulelink'   => 'clientarea.php?action=productdetails&id=' . $serviceId,
        // VPN（服务器形态）一次性查看
        'hasVpn'       => false,
        'vpnUser'      => '',
        'vpnIp'        => '',
        'vpnTarget'    => '',
        'vpnRevealed'  => false,
        'vpnPass'      => '',
        // 服务器形态：连接信息补全（P1）
        'isServer'     => false,
        'gateway'      => '',
        'usableRange'  => '',
        'netmask'      => '',
        'vpnServer'    => '',
        'ipsecPsk'     => '',
        'idracUrl'     => '',
        'idracUser'    => '',
        'idracBuilt'   => false,
    ];

    try {
        Schema::ensureTables();
        $allocObj = Ipam::getAllocation($serviceId);
        if (!$allocObj || $allocObj->status === 'terminated') {
            $vars['error'] = '本服务暂无有效交付记录。';
            return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
        }
        $alloc = (array) $allocObj;

        $vars['deliveryType'] = strtoupper((string) $alloc['delivery_type']);
        $vars['prefix']       = (string) ($alloc['prefix'] ?? '');
        $vars['ptpOur']       = (string) ($alloc['ptp_our'] ?? '');
        $vars['ptpPeer']      = (string) ($alloc['ptp_peer'] ?? '');
        $vars['loopback']     = (string) ($alloc['loopback_ip'] ?? '');
        $vars['tunnelId']     = (string) ($alloc['tunnel_id'] ?? '');
        $vars['remoteIp']     = (string) ($alloc['remote_ip'] ?? '');

        // VPN（服务器形态）：一次性查看凭据
        $vars['vpnUser']     = (string) ($alloc['vpn_user'] ?? '');
        $vars['vpnIp']       = (string) ($alloc['vpn_ip'] ?? '');
        $vars['vpnTarget']   = (string) ($alloc['vpn_target'] ?? '');
        $vars['hasVpn']      = $vars['vpnUser'] !== '';
        $vars['vpnRevealed'] = (int) ($alloc['vpn_revealed'] ?? 0) === 1;
        if ($vars['hasVpn'] && (($_POST['ipd_action'] ?? '') === 'reveal_vpn')) {
            if ($ipdOldToken === '' || !hash_equals($ipdOldToken, (string) ($_POST['ipd_token'] ?? ''))) {
                $vars['error'] = '安全校验失败（token 失效），请刷新后重试。';
            } elseif ($vars['vpnRevealed']) {
                $vars['message'] = 'VPN 密码已查看过（一次性）。如忘记请联系客服重置。';
            } else {
                $vars['vpnPass'] = Config::decrypt((string) ($alloc['vpn_pass_enc'] ?? ''));
                Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)
                    ->update(['vpn_revealed' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
                $vars['vpnRevealed'] = true;
                $vars['message'] = '这是您唯一一次查看 VPN 密码，请立即妥善保存。';
            }
        }

        // 服务器形态：补全交付信息（网关/可用范围/掩码 + VPN 端点/PSK + iDRAC 登录），让非网工客户也能自配。
        if (($alloc['delivery_type'] ?? '') === 'server') {
            $vars['isServer'] = true;
            try {
                $pp  = Templates::parsePrefix((string) ($alloc['prefix'] ?? ''));
                $net = (string) $pp['net'];
                $len = (int) $pp['len'];
                $vars['gateway']     = Templates::firstUsable($net, $len);
                $vars['netmask']     = Ipam::maskLenToDotted($len);
                $vars['usableRange'] = owpprov_usable_range($net, $len);
            } catch (\Throwable $e) {
            }
            // VPN 连接端点 + IPsec PSK（来自绑定的 ROS 设备）
            $rosId = (int) ($alloc['vpn_device_id'] ?? 0);
            if ($rosId > 0) {
                $rosDev = Devices::get($rosId);
                if ($rosDev) {
                    $pub = trim((string) ($rosDev->ros_pub_host ?? ''));
                    $vars['vpnServer'] = $pub !== '' ? $pub : (string) ($rosDev->device_host ?? '');
                }
                $vars['ipsecPsk'] = Config::deviceSecret($rosId, 'ros_ipsec_psk'); // 共享密钥，可直显
            }
            // iDRAC 登录：仅 ipmi_kind=idrac 且本服务 iDRAC 子账号已建成（oplog drac.user=ok）时显示。
            $srv = Servers::byService($serviceId);
            if ($srv && (string) ($srv->ipmi_kind ?? '') === 'idrac' && !empty($srv->ipmi_ip)) {
                $vars['idracUrl']   = 'https://' . (string) $srv->ipmi_ip;
                $vars['idracBuilt'] = Capsule::table(Schema::T_OPLOG)
                    ->where('serviceid', $serviceId)->where('step', 'drac.user')->where('status', 'ok')->exists();
                if ($vars['idracBuilt']) {
                    $vars['idracUser'] = $vars['vpnUser']; // iDRAC 子账号 = VPN 用户名/密码
                }
            }
        }

        // 非 GRE：只读展示（含上方 VPN 区），不提供改对端
        if (($alloc['delivery_type'] ?? '') !== 'gre') {
            if (!$vars['hasVpn'] && $vars['message'] === '') {
                $vars['message'] = '本服务为 XC 交付，无需在客户区改对端。';
            }
            return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
        }

        // 客户侧 GRE 配置提示（在客户自己设备上配）
        $vars['configHint'] = owpprov_gre_client_hint($alloc);

        // 处理 POST：改对端
        $action = $_POST['ipd_action'] ?? $_REQUEST['ipd_action'] ?? '';
        if ($action === 'change_remote') {
            // CSRF：自包含 nonce 校验（提交值 vs 上次渲染存入会话的旧值）
            if ($ipdOldToken === '' || !hash_equals($ipdOldToken, (string) ($_POST['ipd_token'] ?? ''))) {
                $vars['error'] = '安全校验失败（token 失效），请刷新页面后重试。';
                return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
            }

            $newRemote = trim((string) ($_POST['ipd_remote_ip'] ?? ''));
            if (!Ipam::isPublicIpv4($newRemote)) {
                $vars['error'] = '请填写合法的公网 IPv4 对端地址。';
                return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
            }
            if ($newRemote === $vars['remoteIp']) {
                $vars['message'] = '新对端与当前一致，无需变更。';
                return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
            }

            // 限频：N 分钟一次
            $cooldown = (int) $vars['cooldownMins'];
            if (!empty($alloc['remote_changed_at'])) {
                $last = strtotime((string) $alloc['remote_changed_at']);
                if ($last !== false && (time() - $last) < $cooldown * 60) {
                    $wait = $cooldown - (int) floor((time() - $last) / 60);
                    $vars['error'] = '改对端过于频繁，请约 ' . max(1, $wait) . ' 分钟后再试（切换对端会瞬断隧道）。';
                    return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
                }
            }

            // 先连设备改 destination（幂等），成功再写库
            $namingPrefix = (string) ($params['configoption3'] ?? 'WHMCS');
            $custTag      = Templates::custTag($namingPrefix, $serviceId, owpprov_client_name($params));
            $drv          = owpprov_vrp($params, owpprov_alloc_device($alloc));
            $res          = $drv->greChangeRemote($alloc, $newRemote, $custTag);
            logModuleCall('owp_provision', 'ClientArea:ChangeRemote', owpprov_safe_params($params), $res['output'], $res['block']);
            Orchestrator::log($serviceId, 'change', 'vrp.greChangeRemote', owpprov_alloc_device($alloc), (!$res['dryrun'] && !$res['ok']) ? 'failed' : 'ok', '', $newRemote);

            if (!$res['dryrun'] && !$res['ok']) {
                $vars['error'] = '改对端下发失败：' . $res['error'];
                return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
            }

            // 写库 + 回写 custom field
            Ipam::updateRemoteIp($serviceId, $newRemote);
            owpprov_set_customfield($params, 'Remote Endpoint IP', $newRemote);
            if (function_exists('logActivity')) {
                logActivity('[IPDelivery] 服务 #' . $serviceId . ' 客户区改 GRE 对端：'
                    . $vars['remoteIp'] . ' → ' . $newRemote . '。', (int) ($params['userid'] ?? 0));
            }
            $vars['remoteIp'] = $newRemote;
            $vars['message']  = '对端已更新为 ' . htmlspecialchars($newRemote, ENT_QUOTES) . '（隧道可能瞬断后重连）。';
        }

        // 查询隧道状态（只读；dry-run 也允许只读，但失败不致命）
        try {
            $drv   = owpprov_vrp($params, owpprov_alloc_device($alloc));
            $out   = $drv->runDisplay('display interface Tunnel' . (int) $alloc['tunnel_id']);
            $vars['tunnelState'] = $drv->ifaceIsUp($out) ? 'UP' : 'DOWN / 未知';
        } catch (\Throwable $e) {
            $vars['tunnelState'] = '查询失败（' . $e->getMessage() . '）';
        }

        return ['templatefile' => 'clientarea', 'templateVariables' => $vars];

    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        $vars['error'] = '客户区出错：' . $e->getMessage();
        return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
    }
}

// ============================================================================
// Admin 按钮 + 处理器
// ============================================================================

/**
 * 后台自定义按钮。值 = 对应 owp_provision_<value>($params) 处理函数。
 * @return array
 */
function owp_provision_AdminCustomButtonArray()
{
    return [
        'Test Connection'  => 'TestConnection',
        'Re-push Config'   => 'Repush',
        'Show Live Config' => 'ShowConfig',
        'Verify'           => 'VerifyDelivery',
    ];
}

/**
 * 按钮 / 独立：测试连接（跳板→设备 + 写账号 + display version）。
 * @return string 'success' | 错误串
 */
function owp_provision_TestConnection(array $params)
{
    try {
        Schema::ensureTables();
        $deviceId = owpprov_resolve_device($params, null, '');
        if ($deviceId <= 0) {
            return 'Error: 无法确定要测试的设备（多设备且本服务尚无分配时，请到 addon「设备」页用各设备自带的 Test Connection）。';
        }
        $drv = owpprov_vrp($params, $deviceId);
        $res = $drv->testConnection(); // dry-run 内部视为通过；否则写账号 → display version
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $res['output'], $res['error']);
        return $res['ok'] ? 'success' : ('Error: 连接测试失败：' . $res['error']);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 按钮：幂等重下配置（重跑 CreateAccount 的下发部分，复用已有分配）。
 * @return string
 */
function owp_provision_Repush(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $allocObj = Ipam::getAllocation($serviceId);
        if (!$allocObj || $allocObj->status === 'terminated') {
            // 没有分配 → 当作首次开通走完整流程
            return owp_provision_CreateAccount($params);
        }
        $alloc = (array) $allocObj;

        $namingPrefix = (string) ($params['configoption3'] ?? 'WHMCS');
        $custTag      = Templates::custTag($namingPrefix, $serviceId, owpprov_client_name($params));

        return Orchestrator::withLock(function () use ($params, $serviceId, $alloc, $custTag) {
            $devId = owpprov_alloc_device($alloc);
            $drv   = owpprov_vrp($params, $devId);
            $res   = $drv->repush($alloc, $custTag);
            logModuleCall('owp_provision', 'Repush', owpprov_safe_params($params), $res['output'], $res['block']);
            if (!$res['dryrun'] && !$res['ok']) {
                Orchestrator::log($serviceId, 'repush', 'vrp.repush', $devId, 'failed', '', (string) $res['error']);
                return 'Error: 重下失败：' . $res['error'];
            }
            if (!$res['dryrun']) {
                $verify = $drv->verifyDelivery($alloc);
                Orchestrator::log($serviceId, 'repush', 'verify', $devId, $verify['ok'] ? 'ok' : 'failed', '', $verify['ok'] ? '' : (string) $verify['error']);
                if (!$verify['ok']) {
                    return 'Error: 重下后校验未通过：' . $verify['error'];
                }
            } else {
                Orchestrator::log($serviceId, 'repush', 'vrp.repush', $devId, 'dryrun', '', '');
            }
            return 'success';
        });
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 按钮：显示设备上与本服务相关的现状段（display 回读）。
 * 注：AdminCustomButton 只能返回 'success'/错误串；详细回显写进 logModuleCall + mod_owp_provision_log，
 *     staff 去 Utilities>Logs>Module Log 看（按钮无法直接渲染大段文本）。
 * @return string
 */
function owp_provision_ShowConfig(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $alloc = owpprov_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }
        $drv = owpprov_vrp($params, owpprov_alloc_device($alloc));
        if ($drv->isDryRun()) {
            return 'Error: 当前为 dry-run，不读取真实设备。请关闭 dry-run 后再用此按钮。';
        }
        $out = $drv->runDisplay(array_values(Templates::verifyCommands((array) $alloc)));
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $out, '见 Module Log 回显');
        return 'success'; // 回显在 Module Log
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * 按钮：校验交付（接口 UP + 路由命中 + policy 应用）。
 * @return string
 */
function owp_provision_VerifyDelivery(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    try {
        Schema::ensureTables();
        $alloc = owpprov_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }
        $drv = owpprov_vrp($params, owpprov_alloc_device($alloc));
        if ($drv->isDryRun()) {
            return 'Error: 当前为 dry-run，无法校验真实设备。';
        }
        $verify = $drv->verifyDelivery((array) $alloc);
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $verify['detail'] ?? '', $verify['error']);
        return $verify['ok'] ? 'success' : ('Error: 校验未通过：' . $verify['error']);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, owpprov_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

// ============================================================================
// 内部帮手（前缀 ipd_，避免与 WHMCS/其它模块冲突）
// ============================================================================

/**
 * 构造该设备的 VrpDriver（华为 VRP 交换机驱动；内部按 device_id 取连接配置+凭据建 Connection）。
 * dry-run 综合：addon 全局 或 该产品 ConfigOptions。设备不存在/未配置时 VrpDriver 构造抛异常。
 */
function owpprov_vrp(array $params, int $deviceId): VrpDriver
{
    return new VrpDriver($deviceId, Config::isDryRun($params));
}

/** 构造某 ROS 设备的 RosDriver（VPN/iDRAC 通道用）。 */
function owpprov_ros(array $params, int $deviceId): RosDriver
{
    return new RosDriver($deviceId, Config::isDryRun($params));
}

/**
 * 设备驱动工厂：按设备 `driver` 字段返回对应驱动（vrp=华为交换机 / ros=RouterOS）。
 * 用于按设备类型分发（Test Connection、P3 服务器租赁蓝图编排）。
 * @return \OwpProvision\Drivers\DriverInterface
 */
function owpprov_driver(int $deviceId, bool $dryRun)
{
    $dev    = Devices::get($deviceId);
    $driver = $dev ? strtolower((string) ($dev->driver ?? 'vrp')) : 'vrp';
    switch ($driver) {
        case 'ros':
            return new RosDriver($deviceId, $dryRun);
        case 'vrp':
        default:
            return new VrpDriver($deviceId, $dryRun);
    }
}

/**
 * 解析本次操作要连的设备 id。优先级：
 *   1) 传入的分配记录 device_id；
 *   2) 该 serviceid 已有分配的 device_id；
 *   3) 下单所选节点（Configurable Option 值 → 设备 id）；
 *   4) 唯一启用设备（单设备免选）。
 * 都无 → 0（调用方报错）。
 *
 * @param array              $params
 * @param array|object|null  $alloc   已知分配（可空）
 * @param string             $nodeSel 下单所选节点值（可空）
 */
function owpprov_resolve_device(array $params, $alloc = null, string $nodeSel = ''): int
{
    // 1) 已知分配
    if ($alloc !== null) {
        $d = (int) (is_array($alloc) ? ($alloc['device_id'] ?? 0) : ($alloc->device_id ?? 0));
        if ($d > 0) {
            return $d;
        }
    }
    // 2) 该服务已有分配
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId > 0) {
        $a = Ipam::getAllocation($serviceId);
        if ($a && (int) ($a->device_id ?? 0) > 0) {
            return (int) $a->device_id;
        }
    }
    // 3) 下单所选节点
    $devId = owpprov_node_to_device_id($nodeSel);
    if ($devId > 0) {
        return $devId;
    }
    // 4) 单设备免选
    $def = Devices::defaultId();
    return $def > 0 ? $def : 0;
}

/**
 * 取某分配应连的设备 id（生命周期函数用）：记录里的 device_id，缺失则回退默认设备，再兜底 1。
 * @param array|object $alloc
 */
function owpprov_alloc_device($alloc): int
{
    $d = (int) (is_array($alloc) ? ($alloc['device_id'] ?? 0) : ($alloc->device_id ?? 0));
    if ($d > 0) {
        return $d;
    }
    $def = Devices::defaultId();
    return $def > 0 ? $def : 1; // 迁移后至少有「设备 1」；兜底 1
}

/**
 * 把下单节点选项值映射成设备 id。接受：纯数字 id / `dev{id}` / `{id}|label` / `{id}:label` /
 * 设备名（启用设备里不区分大小写匹配）。匹配不到返回 0。
 */
function owpprov_node_to_device_id(string $node): int
{
    $node = trim($node);
    if ($node === '') {
        return 0;
    }
    if (ctype_digit($node)) {
        $id = (int) $node;
        return Devices::exists($id) ? $id : 0;
    }
    if (preg_match('/#(\d+)/', $node, $m)) { // 友好标签含 id，如「Edge-A #1」
        $id = (int) $m[1];
        return Devices::exists($id) ? $id : 0;
    }
    if (preg_match('/^dev(\d+)$/i', $node, $m)) {
        $id = (int) $m[1];
        return Devices::exists($id) ? $id : 0;
    }
    if (preg_match('/^(\d+)\s*[|:]/', $node, $m)) {
        $id = (int) $m[1];
        return Devices::exists($id) ? $id : 0;
    }
    foreach (Devices::enabled() as $d) {
        if (strcasecmp(trim((string) $d->name), $node) === 0) {
            return (int) $d->id;
        }
    }
    return 0;
}

/**
 * 取分配记录；无则返回可读错误串（调用方 is_string() 判断）。
 * @return \stdClass|string
 */
function owpprov_alloc_or_fail(int $serviceId)
{
    if ($serviceId <= 0) {
        return 'Error: 缺少 serviceid。';
    }
    $alloc = Ipam::getAllocation($serviceId);
    if (!$alloc || $alloc->status === 'terminated') {
        return 'Error: 该服务无有效分配记录（尚未开通或已销户）。如需重建请用「Re-push Config」或重新开通。';
    }
    return $alloc;
}

/**
 * 创建失败回滚：尽力拆除已下发部分 + 释放分配。失败只记日志（不二次抛）。
 * 校验交付/拆除逻辑已收进 VrpDriver::verifyDelivery / verifyTeardown。
 */
function owpprov_rollback_create(VrpDriver $drv, array $alloc, int $serviceId): void
{
    try {
        $drv->teardown($alloc); // VrpDriver 内部按类型渲染 undo 命令块（best-effort）
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'owpprov_rollback_create', ['serviceid' => $serviceId], $e->getMessage(), '');
    }
    try {
        Ipam::release($serviceId);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'owpprov_rollback_create:release', ['serviceid' => $serviceId], $e->getMessage(), '');
    }
}

/**
 * 回写 custom fields（VLAN/PTP/Prefix/Tunnel/Loopback；管理员只读，权威仍在 allocations）。
 */
function owpprov_writeback_customfields(array $params, array $alloc): void
{
    try {
        owpprov_set_customfield($params, 'Allocated VLAN', (string) ($alloc['vlan_id'] ?? ''));
        owpprov_set_customfield($params, 'PTP', trim(((string) ($alloc['ptp_our'] ?? '')) . ' / ' . ((string) ($alloc['ptp_peer'] ?? '')), ' /'));
        owpprov_set_customfield($params, 'Delivered Prefix', (string) ($alloc['prefix'] ?? ''));
        $tun = '';
        if (($alloc['delivery_type'] ?? '') === 'gre') {
            $tun = 'Tunnel' . ($alloc['tunnel_id'] ?? '') . ' / Loop ' . ($alloc['loopback_ip'] ?? '');
        }
        owpprov_set_customfield($params, 'Tunnel/Loopback', $tun);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'owpprov_writeback_customfields', ['serviceid' => $params['serviceid'] ?? 0], $e->getMessage(), '');
    }
}

/**
 * 写一个 custom field 值（按字段名找该产品的 tblcustomfields.id，再 upsert tblcustomfieldsvalues）。
 * 字段不存在则静默跳过（管理员可能没建全回写字段）。
 */
function owpprov_set_customfield(array $params, string $fieldName, string $value): void
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $pid       = (int) ($params['pid'] ?? 0);
    if ($serviceId <= 0) {
        return;
    }
    try {
        $q = Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', $fieldName);
        if ($pid > 0) {
            $q->where('relid', $pid);
        }
        $fieldId = (int) $q->value('id');
        if ($fieldId <= 0) {
            return; // 该回写字段未创建
        }
        $exists = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)->where('relid', $serviceId)->exists();
        if ($exists) {
            Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)->where('relid', $serviceId)
                ->update(['value' => $value]);
        } else {
            Capsule::table('tblcustomfieldsvalues')->insert([
                'fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value,
            ]);
        }
    } catch (\Throwable $e) {
        // 回写非关键，吞掉
    }
}

/**
 * 从 configoptions（按名）取值；多名兜底；缺省回退 $default。
 */
function owpprov_pluck_co(array $params, array $names, string $default): string
{
    if (isset($params['configoptions']) && is_array($params['configoptions'])) {
        foreach ($names as $n) {
            if (isset($params['configoptions'][$n]) && $params['configoptions'][$n] !== '') {
                return (string) $params['configoptions'][$n];
            }
        }
    }
    return $default;
}

/**
 * 从 customfields（按名）取值；多名兜底；缺省回退 $default。
 */
function owpprov_pluck_cf(array $params, array $names, string $default): string
{
    if (isset($params['customfields']) && is_array($params['customfields'])) {
        foreach ($names as $n) {
            if (array_key_exists($n, $params['customfields']) && $params['customfields'][$n] !== '') {
                return (string) $params['customfields'][$n];
            }
        }
    }
    return $default;
}

/**
 * 交付类型缺省：若没有 Configurable Option，则看不出来——返回空让上层报错（强制配 CO）。
 * 也可在此读某 configoptionN 作默认；当前不强加默认（避免误开错类型）。
 */
function owpprov_default_delivery(array $params): string
{
    return '';
}

/** 客户名（firstname+lastname 或 companyname）。 */
function owpprov_client_name(array $params): string
{
    $cd = $params['clientsdetails'] ?? [];
    if (is_array($cd)) {
        $company = trim((string) ($cd['companyname'] ?? ''));
        if ($company !== '') {
            return $company;
        }
        $name = trim(((string) ($cd['firstname'] ?? '')) . ' ' . ((string) ($cd['lastname'] ?? '')));
        if ($name !== '') {
            return $name;
        }
    }
    return 'cust';
}

/**
 * 确保服务有可用的 VPN/iDRAC 凭据并返回 [username, password]（明文）。
 *
 * WHMCS 对「Other」类产品常只生成 password、不生成 username → 直接用 $params['username'] 会是空串，
 * 导致 VPN/iDRAC 整段被跳过。此处：username 为空则生成确定性安全名 `svc<serviceid>`
 * （`[a-z0-9]`，RouterOS ppp secret / iDRAC 用户名均安全）；password 为空则随机生成强密码；
 * 二者按需**存回服务**（password 走 WHMCS 加密），使客户区「一次性查看」与后续步骤一致。
 * **幂等**：已有的不覆盖。
 *
 * @return array{0:string,1:string} [username, password]（明文）
 */
function owpprov_ensure_service_credentials(array $params): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $user      = trim((string) ($params['username'] ?? ''));
    $pass      = (string) ($params['password'] ?? '');

    $update = [];
    if ($user === '') {
        $user           = 'svc' . $serviceId;          // 确定性 + 唯一 + ppp/iDRAC 安全字符
        $update['username'] = $user;
    }
    if ($pass === '') {
        $pass = owpprov_random_password(20);
        try {
            $enc = function_exists('localAPI')
                ? (string) (localAPI('EncryptPassword', ['password2' => $pass])['password'] ?? '')
                : '';
        } catch (\Throwable $e) {
            $enc = '';
        }
        if ($enc !== '') {
            $update['password'] = $enc;               // tblhosting.password 存 WHMCS 加密串
        }
    }

    if ($update && $serviceId > 0) {
        try {
            Capsule::table('tblhosting')->where('id', $serviceId)->update($update);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall('owp_provision', 'ensure_credentials', ['serviceid' => $serviceId], $e->getMessage(), '');
            }
        }
    }
    return [$user, $pass];
}

/** 生成强随机密码（去除易混淆字符 0/O/1/l/I）。 */
function owpprov_random_password(int $len = 20): string
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $out   = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/** GRE 客户侧配置提示（客户在自己设备上配 GRE 用）。 */
/**
 * 服务器交付段的「客户可用 IP 范围」（网关之后到广播之前）。
 * /≤30：网关=base+1 → 客户用 base+2 … 广播-1；/31：RFC3021 客户用 base+1；/32：单 IP。
 */
function owpprov_usable_range(string $net, int $len): string
{
    $base = ip2long($net);
    if ($base === false) {
        return '';
    }
    if ($len >= 32) {
        return long2ip($base);
    }
    if ($len === 31) {
        return long2ip($base + 1); // RFC3021：交换机 .0 / 客户 .1
    }
    $size  = 1 << (32 - $len);
    $first = $base + 2;            // 网关 base+1，客户从 base+2 起
    $last  = $base + $size - 2;    // 广播 base+size-1，客户到 broadcast-1
    if ($first > $last) {
        return long2ip($first);
    }
    return $first === $last ? long2ip($first) : (long2ip($first) . ' – ' . long2ip($last));
}

function owpprov_gre_client_hint(array $alloc): string
{
    $prefix   = (string) ($alloc['prefix'] ?? '');
    $tranOur  = (string) ($alloc['ptp_our'] ?? '');   // 我方 transit
    $tranPeer = (string) ($alloc['ptp_peer'] ?? '');  // 客户侧 transit
    $loop     = (string) ($alloc['loopback_ip'] ?? '');
    $remote   = (string) ($alloc['remote_ip'] ?? '');
    return "GRE 隧道参数（在您自己的设备上配置）：\n"
        . "  对端(我方)隧道源 IP（destination 指向它）: {$loop}\n"
        . "  您的隧道源 IP（destination = 您填的对端）: {$remote}\n"
        . "  Transit /30：我方 {$tranOur}，您侧 {$tranPeer}\n"
        . "  隧道内您侧地址用 {$tranPeer}/30，MTU 1476，tunnel-protocol gre\n"
        . "  交付给您的网段：{$prefix}（请在您侧把该段指向隧道 / 用作业务）";
}

// 客户区 CSRF 已改用自包含一次性 nonce（$_SESSION['ipd_csrf_ca'] ↔ 表单隐藏字段 ipd_token），
// 校验内联在 owp_provision_ClientArea() 里；旧的独立 token 校验函数已移除。

/**
 * 脱敏 $params 再喂给 logModuleCall（去掉可能的密码键；configoptions/customfields 保留，
 * 不含设备密钥）。logModuleCall 自身也会脱敏常见键，这里再加一层。
 */
function owpprov_safe_params(array $params): array
{
    $safe = $params;
    foreach (['password', 'serverpassword', 'serveraccesshash', 'writePass', 'readPass'] as $k) {
        if (isset($safe[$k])) {
            $safe[$k] = '***';
        }
    }
    return $safe;
}

/** 结构化日志小工具（Module Log）。 */
function owpprov_log(string $action, array $params, string $request, string $response): void
{
    logModuleCall('owp_provision', $action, owpprov_safe_params($params), $response, $request);
}
