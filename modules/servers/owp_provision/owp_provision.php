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
 *    落一遍真实结构再调键名。下方 ipd_pluck*() 已做多名兜底。
 *
 * @package OwpProvision
 * @target  WHMCS 9.0.4 / PHP 8.3
 */

use WHMCS\Database\Capsule;
use OwpProvision\Schema;
use OwpProvision\Config;
use OwpProvision\Devices;
use OwpProvision\Ipam;
use OwpProvision\Templates;
use OwpProvision\Connection;
use OwpProvision\Types;

if (!defined('WHMCS')) {
    die('Access Denied');
}

// ---- 共用 lib 加载（server 与 addon 共用同一套；addon 也 require 这个目录） -------
require_once __DIR__ . '/lib/Schema.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Devices.php';
require_once __DIR__ . '/lib/Types.php';
require_once __DIR__ . '/lib/Ipam.php';
require_once __DIR__ . '/lib/Resources.php';
require_once __DIR__ . '/lib/Templates.php';
require_once __DIR__ . '/lib/Connection.php';

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

        // 1) 读参数
        $deliveryType = strtolower(ipd_pluck_co($params, ['delivery_type', 'Delivery Type'], ipd_default_delivery($params)));
        $bandwidth    = ipd_pluck_co($params, ['bandwidth', 'Bandwidth'], (string) ($params['configoption1'] ?? '100M'));
        $prefixSize   = ipd_pluck_co($params, ['prefix_size', 'Prefix Size'], (string) ($params['configoption2'] ?? '/32'));
        $namingPrefix = (string) ($params['configoption3'] ?? 'WHMCS');
        $remoteIp     = trim(ipd_pluck_cf($params, ['Remote Endpoint IP', 'Remote IP'], ''));
        $wantPort     = trim(ipd_pluck_cf($params, ['XC Port', 'Port'], ''));
        $nodeSel      = ipd_pluck_co($params, ['node', 'Node', 'device', 'Device', '节点'], '');
        $clientName   = ipd_client_name($params);
        $custTag      = Templates::custTag($namingPrefix, $serviceId, $clientName);

        // 节点（设备）确定：下单所选 → 单设备免选默认。无法确定/未启用即报错。
        $deviceId = ipd_resolve_device($params, null, $nodeSel);
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

        // 3) 事务分配资源（从所选设备的资源池分配，写 allocation.device_id）
        try {
            if ($deliveryType === 'xc') {
                $alloc = Ipam::allocateXc($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $wantPort !== '' ? $wantPort : null);
            } else {
                $alloc = Ipam::allocateGre($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $remoteIp);
            }
        } catch (\Throwable $e) {
            ipd_log(__FUNCTION__, $params, '', '分配失败：' . $e->getMessage());
            return 'Error: 资源分配失败：' . $e->getMessage();
        }
        // 幂等复用旧分配时，以记录里的 device_id 为准（连对同一台设备）
        $deviceId = (int) ($alloc['device_id'] ?? $deviceId);

        // 4) 连接（按本服务设备；dry-run 时 Connection 内部不触设备）
        $conn   = ipd_connection($params, $deviceId);
        $isDry  = $conn->isDryRun();

        // 5) 幂等预检（非 dry-run 才真读；dry-run 跳过设备读）
        //    预检只为日志/参考，真正幂等性靠命令本身（VRP 重复下发同配置不报错；
        //    undo 不存在对象会报错，但 Create 路径不 undo）。
        if (!$isDry) {
            try {
                $pre = $conn->runDisplay(array_values(Templates::verifyCommands($alloc)));
                ipd_log(__FUNCTION__ . ':precheck', $params, '', $pre);
            } catch (\Throwable $e) {
                // 预检失败不致命（可能对象本就不存在）；继续下发，由下发+校验把关。
                ipd_log(__FUNCTION__ . ':precheck', $params, '', '预检读失败（忽略）：' . $e->getMessage());
            }
        }

        // 6) 渲染命令块（走类型注册表的 create 方法，便于将来加类型不改此处）
        $createMethod = $typeDef['create'];
        $lines = Templates::$createMethod($alloc, $custTag);

        // 7) 下发（含 save+Y；dry-run 只记日志）
        $res = $conn->runConfig($lines, $serviceId, 'CreateAccount');
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['block']);

        if ($res['dryrun']) {
            // dry-run：回写 custom fields（便于后台看分配结果），返回成功（不触设备）。
            ipd_writeback_customfields($params, $alloc);
            return 'success';
        }
        if (!$res['ok']) {
            // 下发失败 → 尽力回滚已下发部分 + 释放分配
            ipd_rollback_create($conn, $alloc, $serviceId);
            return 'Error: 下发失败，已尝试回滚并释放分配：' . $res['error'];
        }

        // 8) 校验回读（顾问性，不作为回滚依据）
        //    交付段/接口能否「活」依赖客户侧是否就绪——开通时客户多半还没配好自己那端
        //    （XC 的 Vlanif 要成员口 up 才 up；静态路由 nexthop 不可达就不进表）。
        //    故 liveness 不当失败：下发成功 + save 成功（runConfig 已把关）即视为开通成功；
        //    liveness 仅记日志，管理员可在客户就绪后用后台「Verify」按钮复检。
        $verify = ipd_verify_delivery($conn, $alloc);
        if (!$verify['ok']) {
            logModuleCall('owp_provision', __FUNCTION__ . ':verify-advisory',
                ['serviceid' => $serviceId], (string) ($verify['detail'] ?? ''),
                '已下发并保存；liveness 暂未通过（通常因客户侧未就绪，非错误）：' . $verify['error']);
            if (function_exists('logActivity')) {
                logActivity('[IPDelivery] 服务 #' . $serviceId . ' 配置已下发并保存；'
                    . 'liveness 暂未通过（多因客户侧未就绪，可稍后 Verify 复检）：' . $verify['error']);
            }
        }

        // 9) 回写 custom fields + 活动日志
        ipd_writeback_customfields($params, $alloc);
        if (function_exists('logActivity')) {
            logActivity('[IPDelivery] 服务 #' . $serviceId . ' 已开通（' . strtoupper($deliveryType)
                . '，prefix=' . ($alloc['prefix'] ?? '') . '）。');
        }
        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $alloc = ipd_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }

        $conn = ipd_connection($params, ipd_alloc_device($alloc));
        $res  = $conn->runConfig(Templates::suspend((array) $alloc), $serviceId, 'SuspendAccount');
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['block']);

        if (!$res['dryrun'] && !$res['ok']) {
            return 'Error: 暂停下发失败：' . $res['error'];
        }
        Ipam::setStatus($serviceId, 'suspended');
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $alloc = ipd_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }

        $conn = ipd_connection($params, ipd_alloc_device($alloc));
        $res  = $conn->runConfig(Templates::unsuspend((array) $alloc), $serviceId, 'UnsuspendAccount');
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['block']);

        if (!$res['dryrun'] && !$res['ok']) {
            return 'Error: 恢复下发失败：' . $res['error'];
        }
        Ipam::setStatus($serviceId, 'active');
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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

        $conn  = ipd_connection($params, ipd_alloc_device($alloc));
        $tm    = ipd_type_method($alloc, 'teardown');
        $lines = $tm ? Templates::$tm($alloc) : [];

        $res = $conn->runConfig($lines, $serviceId, 'TerminateAccount');
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['block']);

        if ($res['dryrun']) {
            Ipam::release($serviceId);
            return 'success';
        }
        if (!$res['ok']) {
            // 拆除失败：不释放分配（避免「记录回池但设备仍有残留」），返回错误让 staff 介入。
            return 'Error: 拆除下发失败（资源未回池，待人工核查设备残留）：' . $res['error'];
        }

        // 校验已清除（best-effort）：接口/路由应不再命中
        $verify = ipd_verify_teardown($conn, $alloc);
        if (!$verify['ok']) {
            // 设备可能仍有残留：保留分配记录、返回错误（不静默回池）。
            return 'Error: 拆除后仍检测到残留，请人工核查（资源暂不回池）：' . $verify['error'];
        }

        Ipam::release($serviceId);
        if (function_exists('logActivity')) {
            logActivity('[IPDelivery] 服务 #' . $serviceId . ' 已销户拆除并回收资源。');
        }
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $alloc = ipd_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }
        $alloc = (array) $alloc;

        $newBw   = ipd_pluck_co($params, ['bandwidth', 'Bandwidth'], (string) ($alloc['bandwidth'] ?? ''));
        $newSize = ipd_pluck_co($params, ['prefix_size', 'Prefix Size'], '');

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
        $conn = ipd_connection($params, ipd_alloc_device($alloc));
        $res  = $conn->runConfig($bwLines, $serviceId, 'ChangePackage');
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['block']);

        if (!$res['dryrun'] && !$res['ok']) {
            return 'Error: 改带宽下发失败：' . $res['error'];
        }
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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

        // 非 GRE：只读展示，不提供改对端
        if (($alloc['delivery_type'] ?? '') !== 'gre') {
            $vars['message'] = '本服务为 XC 交付，无需在客户区改对端。';
            return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
        }

        // 客户侧 GRE 配置提示（在客户自己设备上配）
        $vars['configHint'] = ipd_gre_client_hint($alloc);

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
            $custTag      = Templates::custTag($namingPrefix, $serviceId, ipd_client_name($params));
            $conn         = ipd_connection($params, ipd_alloc_device($alloc));
            $res          = $conn->runConfig(
                Templates::greChangeRemote($alloc, $newRemote, $custTag),
                $serviceId,
                'ChangeRemote'
            );
            logModuleCall('owp_provision', 'ClientArea:ChangeRemote', ipd_safe_params($params), $res['output'], $res['block']);

            if (!$res['dryrun'] && !$res['ok']) {
                $vars['error'] = '改对端下发失败：' . $res['error'];
                return ['templatefile' => 'clientarea', 'templateVariables' => $vars];
            }

            // 写库 + 回写 custom field
            Ipam::updateRemoteIp($serviceId, $newRemote);
            ipd_set_customfield($params, 'Remote Endpoint IP', $newRemote);
            if (function_exists('logActivity')) {
                logActivity('[IPDelivery] 服务 #' . $serviceId . ' 客户区改 GRE 对端：'
                    . $vars['remoteIp'] . ' → ' . $newRemote . '。', (int) ($params['userid'] ?? 0));
            }
            $vars['remoteIp'] = $newRemote;
            $vars['message']  = '对端已更新为 ' . htmlspecialchars($newRemote, ENT_QUOTES) . '（隧道可能瞬断后重连）。';
        }

        // 查询隧道状态（只读；dry-run 也允许只读，但失败不致命）
        try {
            $conn  = ipd_connection($params, ipd_alloc_device($alloc));
            $out   = $conn->runDisplay('display interface Tunnel' . (int) $alloc['tunnel_id']);
            $vars['tunnelState'] = $conn->ifaceIsUp($out) ? 'UP' : 'DOWN / 未知';
        } catch (\Throwable $e) {
            $vars['tunnelState'] = '查询失败（' . $e->getMessage() . '）';
        }

        return ['templatefile' => 'clientarea', 'templateVariables' => $vars];

    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $deviceId = ipd_resolve_device($params, null, '');
        if ($deviceId <= 0) {
            return 'Error: 无法确定要测试的设备（多设备且本服务尚无分配时，请到 addon「设备」页用各设备自带的 Test Connection）。';
        }
        $conn = ipd_connection($params, $deviceId);
        if ($conn->isDryRun()) {
            return 'success'; // dry-run：视为「配置可读」即通过（不触设备）
        }
        // 优先用写账号测（验证自动化账号可用）
        $res = $conn->testConnection(true);
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['error']);
        if ($res['ok']) {
            return 'success';
        }
        return 'Error: 连接测试失败：' . $res['error'];
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $custTag      = Templates::custTag($namingPrefix, $serviceId, ipd_client_name($params));
        $cm    = ipd_type_method($alloc, 'create');
        $lines = $cm ? Templates::$cm($alloc, $custTag) : [];

        $conn = ipd_connection($params, ipd_alloc_device($alloc));
        $res  = $conn->runConfig($lines, $serviceId, 'Repush');
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $res['output'], $res['block']);

        if (!$res['dryrun'] && !$res['ok']) {
            return 'Error: 重下失败：' . $res['error'];
        }
        if (!$res['dryrun']) {
            $verify = ipd_verify_delivery($conn, $alloc);
            if (!$verify['ok']) {
                return 'Error: 重下后校验未通过：' . $verify['error'];
            }
        }
        return 'success';
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $alloc = ipd_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }
        $conn = ipd_connection($params, ipd_alloc_device($alloc));
        if ($conn->isDryRun()) {
            return 'Error: 当前为 dry-run，不读取真实设备。请关闭 dry-run 后再用此按钮。';
        }
        $out = $conn->runDisplay(array_values(Templates::verifyCommands((array) $alloc)));
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $out, '见 Module Log 回显');
        return 'success'; // 回显在 Module Log
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
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
        $alloc = ipd_alloc_or_fail($serviceId);
        if (is_string($alloc)) {
            return $alloc;
        }
        $conn = ipd_connection($params, ipd_alloc_device($alloc));
        if ($conn->isDryRun()) {
            return 'Error: 当前为 dry-run，无法校验真实设备。';
        }
        $verify = ipd_verify_delivery($conn, (array) $alloc);
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $verify['detail'] ?? '', $verify['error']);
        return $verify['ok'] ? 'success' : ('Error: 校验未通过：' . $verify['error']);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', __FUNCTION__, ipd_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

// ============================================================================
// 内部帮手（前缀 ipd_，避免与 WHMCS/其它模块冲突）
// ============================================================================

/**
 * 构造 Connection（按 **指定设备** 的连接配置 + 凭据；综合 dry-run：addon 全局 或 该产品 ConfigOptions）。
 * @throws \RuntimeException 设备不存在/未配置
 */
function ipd_connection(array $params, int $deviceId): Connection
{
    $cfg = Devices::connConfig($deviceId);
    if (empty($cfg)) {
        throw new \RuntimeException('设备 #' . $deviceId . ' 不存在或未配置连接信息。请在 addon「设备」页检查。');
    }
    $isDry = Config::isDryRun($params);
    return new Connection($cfg, $isDry);
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
function ipd_resolve_device(array $params, $alloc = null, string $nodeSel = ''): int
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
    $devId = ipd_node_to_device_id($nodeSel);
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
function ipd_alloc_device($alloc): int
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
function ipd_node_to_device_id(string $node): int
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
 * 取某分配对应交付类型在 Templates 上的方法名（'create' / 'teardown'）；未知类型返回 null。
 * 让开通/拆除走类型注册表分发，新增类型不改核心。
 */
function ipd_type_method(array $alloc, string $which): ?string
{
    $d = Types::get((string) ($alloc['delivery_type'] ?? ''));
    return $d[$which] ?? null;
}

/**
 * 取分配记录；无则返回可读错误串（调用方 is_string() 判断）。
 * @return \stdClass|string
 */
function ipd_alloc_or_fail(int $serviceId)
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
 * 校验交付：接口 UP + 路由命中 + policy 应用。返回 ['ok'=>bool,'error'=>..,'detail'=>..]。
 */
function ipd_verify_delivery(Connection $conn, array $alloc): array
{
    $cmds = Templates::verifyCommands($alloc);
    $detail = [];
    $errors = [];

    try {
        $ifaceOut = $conn->runDisplay($cmds['iface']);
        $detail['iface'] = $ifaceOut;
        if (!$conn->ifaceIsUp($ifaceOut)) {
            $errors[] = '接口未 UP';
        }
    } catch (\Throwable $e) {
        $errors[] = '读接口失败：' . $e->getMessage();
    }

    try {
        $pp  = Templates::parsePrefix((string) $alloc['prefix']);
        $net = (string) $pp['net'];
        $routeOut = $conn->runDisplay($cmds['route']);
        $detail['route'] = $routeOut;
        if (!$conn->routeHit($routeOut, $net, $pp['len'])) {
            $errors[] = '路由未进表（' . $net . '/' . $pp['len'] . '）';
        }
    } catch (\Throwable $e) {
        $errors[] = '读路由失败：' . $e->getMessage();
    }

    // XC 用端口 qos lr、隧道不限速 → 不再查 traffic-policy applied-record。
    // 校验只看接口 UP + 路由命中（liveness 在 CreateAccount 里是顾问性，不致回滚）。
    $warnings = [];

    $msg = implode('；', $errors);
    if (!empty($warnings)) {
        $msg .= ($msg !== '' ? '；' : '') . '[告警] ' . implode('；', $warnings);
    }
    return [
        'ok'     => empty($errors),  // 仅 iface/route 计入失败；traffic-policy 仅告警
        'error'  => $msg,
        'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * 校验拆除：接口/路由应不再命中。
 */
function ipd_verify_teardown(Connection $conn, array $alloc): array
{
    $cmds   = Templates::teardownVerifyCommands($alloc);
    $errors = [];

    try {
        $ifaceOut = $conn->runDisplay($cmds['iface']);
        // 接口已删 → display 应报「不存在」之类；若仍 UP 视为残留。
        if ($conn->ifaceIsUp($ifaceOut)) {
            $errors[] = '接口仍存在/UP';
        }
    } catch (\Throwable $e) {
        // 读失败可能正是因为接口已不存在；不计为错误。
    }

    try {
        $pp       = Templates::parsePrefix((string) $alloc['prefix']);
        $net      = (string) $pp['net'];
        $routeOut = $conn->runDisplay($cmds['route']);
        if ($conn->routeHit($routeOut, $net, $pp['len'])) {
            $errors[] = '路由仍在表（' . $net . '/' . $pp['len'] . '）';
        }
    } catch (\Throwable $e) {
        // 同上
    }

    return ['ok' => empty($errors), 'error' => implode('；', $errors)];
}

/**
 * 创建失败回滚：尽力拆除已下发部分 + 释放分配。失败只记日志（不要二次抛）。
 */
function ipd_rollback_create(Connection $conn, array $alloc, int $serviceId): void
{
    try {
        $tm    = ipd_type_method($alloc, 'teardown');
        $lines = $tm ? Templates::$tm($alloc) : [];
        // 注：teardown 里若某对象其实没建成功，undo 会报错——这是 best-effort 回滚，
        // 忽略其报错（已在 runConfig 内识别但我们这里不据此再失败）。
        if (!empty($lines)) {
            $conn->runConfig($lines, $serviceId, 'RollbackCreate');
        }
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'ipd_rollback_create', ['serviceid' => $serviceId], $e->getMessage(), '');
    }
    try {
        Ipam::release($serviceId);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'ipd_rollback_create:release', ['serviceid' => $serviceId], $e->getMessage(), '');
    }
}

/**
 * 回写 custom fields（VLAN/PTP/Prefix/Tunnel/Loopback；管理员只读，权威仍在 allocations）。
 */
function ipd_writeback_customfields(array $params, array $alloc): void
{
    try {
        ipd_set_customfield($params, 'Allocated VLAN', (string) ($alloc['vlan_id'] ?? ''));
        ipd_set_customfield($params, 'PTP', trim(((string) ($alloc['ptp_our'] ?? '')) . ' / ' . ((string) ($alloc['ptp_peer'] ?? '')), ' /'));
        ipd_set_customfield($params, 'Delivered Prefix', (string) ($alloc['prefix'] ?? ''));
        $tun = '';
        if (($alloc['delivery_type'] ?? '') === 'gre') {
            $tun = 'Tunnel' . ($alloc['tunnel_id'] ?? '') . ' / Loop ' . ($alloc['loopback_ip'] ?? '');
        }
        ipd_set_customfield($params, 'Tunnel/Loopback', $tun);
    } catch (\Throwable $e) {
        logModuleCall('owp_provision', 'ipd_writeback_customfields', ['serviceid' => $params['serviceid'] ?? 0], $e->getMessage(), '');
    }
}

/**
 * 写一个 custom field 值（按字段名找该产品的 tblcustomfields.id，再 upsert tblcustomfieldsvalues）。
 * 字段不存在则静默跳过（管理员可能没建全回写字段）。
 */
function ipd_set_customfield(array $params, string $fieldName, string $value): void
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
function ipd_pluck_co(array $params, array $names, string $default): string
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
function ipd_pluck_cf(array $params, array $names, string $default): string
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
function ipd_default_delivery(array $params): string
{
    return '';
}

/** 客户名（firstname+lastname 或 companyname）。 */
function ipd_client_name(array $params): string
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

/** GRE 客户侧配置提示（客户在自己设备上配 GRE 用）。 */
function ipd_gre_client_hint(array $alloc): string
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
// 校验内联在 owp_provision_ClientArea() 里；旧的 ipd_check_token() 已移除。

/**
 * 脱敏 $params 再喂给 logModuleCall（去掉可能的密码键；configoptions/customfields 保留，
 * 不含设备密钥）。logModuleCall 自身也会脱敏常见键，这里再加一层。
 */
function ipd_safe_params(array $params): array
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
function ipd_log(string $action, array $params, string $request, string $response): void
{
    logModuleCall('owp_provision', $action, ipd_safe_params($params), $response, $request);
}
