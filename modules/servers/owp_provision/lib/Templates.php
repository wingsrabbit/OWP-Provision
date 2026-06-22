<?php
/**
 * IP-Delivery — Templates.php
 * ----------------------------------------------------------------------------
 * 渲染 接入交换机 的 VRP 命令块：XC 开通 / GRE 开通 / traffic-policy 限速 /
 * 拆除（teardown）/ 暂停 / 恢复 / 改 GRE 对端，以及 display 预检 + 校验命令。
 *
 * 🔴 硬约束：本模块**只动 接入交换机 接入层**——
 *    vlan / port / Vlanif / Tunnel / LoopBack / ip route-static / traffic-policy / save。
 *    **绝不**渲染 BGP `network`、route-policy、AS、或任何 上游路由器 改动。
 *    交付段是「已宣告聚合内的更具体段」，靠聚合覆盖对外，人工预置 = 前提条件。
 *
 * 命令模板针对华为 VRP 接入交换机
 * 
 *
 * 约定：
 *  - XC PTP 用 /30（255.255.255.252，our=.X+1 / peer=.X+2）。
 *    注：PTP 互联统一用 /30。
 *  - traffic classifier/behavior/policy 命名以 serviceid 唯一化：tp-{id}/tc-{id}/tb-{id}。
 *  - 每个配置块以 system-view 开头、return 结尾，由 Connection 负责追加 save + Y。
 *
 * 所有方法返回「命令行数组」（每元素一行，不含尾换行）；Connection::buildBlock() 拼成块。
 *
 * @package OwpProvision
 * @target  WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Templates
{
    /** classifier 名。 */
    public static function classifierName(int $serviceId): string
    {
        return 'tc-' . $serviceId;
    }

    /** behavior 名。 */
    public static function behaviorName(int $serviceId): string
    {
        return 'tb-' . $serviceId;
    }

    /** policy 名（与 Ipam::policyName 一致）。 */
    public static function policyName(int $serviceId): string
    {
        return 'tp-' . $serviceId;
    }

    /**
     * 带宽档 → CIR(kbps)。规则：1M = 1024 kbps（与 spec 例 100M→102400 一致）。
     * 支持 '100M' / '500M' / '1G' / '1000M' / 纯数字(视为 Mbps) / '512K'。
     *
     * @param string $bandwidth
     * @return int kbps（>=1）
     * @throws \RuntimeException 无法解析时
     */
    public static function bandwidthToCirKbps(string $bandwidth): int
    {
        $bw = strtoupper(trim($bandwidth));
        if ($bw === '') {
            throw new \RuntimeException('带宽档为空，无法换算 CIR。');
        }
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([GMK]?)(?:BPS|B)?$/', $bw, $m)) {
            $num  = (float) $m[1];
            $unit = $m[2] !== '' ? $m[2] : 'M'; // 无单位默认按 Mbps
            $kbps = match ($unit) {
                'G'     => $num * 1024 * 1024,
                'M'     => $num * 1024,
                'K'     => $num,
                default => $num * 1024,
            };
            $kbps = (int) round($kbps);
            return max(1, $kbps);
        }
        throw new \RuntimeException('无法解析带宽档：' . $bandwidth);
    }

    /**
     * description 标签：用命名前缀 + serviceid + 客户名，安全化（VRP description 限字符）。
     * 例：WHMCS-1234-JohnDoe
     */
    public static function custTag(string $namingPrefix, int $serviceId, string $clientName): string
    {
        $prefix = self::sanitizeDesc($namingPrefix !== '' ? $namingPrefix : 'WHMCS');
        $name   = self::sanitizeDesc($clientName);
        $tag    = $prefix . '-' . $serviceId . ($name !== '' ? '-' . $name : '');
        // VRP description 一般 <=242 字符；接口/vlan description 保守截断到 80。
        return substr($tag, 0, 80);
    }

    /** description 只留安全字符（字母数字 . _ -），空格转 -。 */
    public static function sanitizeDesc(string $s): string
    {
        $s = preg_replace('/\s+/', '-', trim($s));
        $s = preg_replace('/[^A-Za-z0-9._-]/', '', $s);
        return trim($s, '-');
    }

    // ======================================================================
    // XC 开通
    // ======================================================================

    /**
     * 渲染 XC 开通命令（含 traffic-policy）。
     *
     * 需要的 allocation 键：vlan_id, ptp_our, ptp_peer, port, prefix, bandwidth。
     *
     * @param array  $alloc   Ipam 分配数组
     * @param string $custTag description 标签
     * @return string[] 命令行数组（system-view ... return；不含 save/Y）
     */
    public static function xcCreate(array $alloc, string $custTag): array
    {
        $vid     = (int) $alloc['vlan_id'];
        $ptpOur  = (string) $alloc['ptp_our'];
        $ptpPeer = (string) $alloc['ptp_peer'];
        $port    = (string) $alloc['port'];
        $prefix  = self::parsePrefix((string) $alloc['prefix']);

        $lines = [];
        $lines[] = 'system-view';
        // VLAN
        $lines[] = 'vlan ' . $vid;
        $lines[] = ' description ' . $custTag;
        $lines[] = 'quit';
        // Vlanif (PTP 我方 /30)
        $lines[] = 'interface Vlanif' . $vid;
        $lines[] = ' description ' . $custTag;
        $lines[] = ' ip address ' . $ptpOur . ' 255.255.255.252';
        $lines[] = 'quit';
        // 物理口入 VLAN（access）+ 端口整口限速 qos lr。
        // XC = 物理交叉连接「一个口=一个客户」，整口限速正好限这个客户；
        // 实测 qos lr 走端口整形资源，**不占**紧张的出向 EACL slice → 规模化无瓶颈。
        $cir = self::bandwidthToCirKbps((string) ($alloc['bandwidth'] ?? '100M'));
        $lines[] = 'interface ' . $port;
        $lines[] = ' description ' . $custTag;
        $lines[] = ' port link-type access';
        $lines[] = ' port default vlan ' . $vid;
        $lines[] = ' qos lr inbound cir ' . $cir;   // 入向限速（设备自动补 cbs，无需手写）
        $lines[] = ' qos lr outbound cir ' . $cir;  // 出向限速
        $lines[] = 'quit';
        // 客户段静态路由 → PTP 对端
        $lines[] = 'ip route-static ' . $prefix['net'] . ' ' . $prefix['mask'] . ' ' . $ptpPeer;

        $lines[] = 'return';
        return $lines;
    }

    // ======================================================================
    // GRE 开通
    // ======================================================================

    /**
     * 渲染 GRE 开通命令（含 traffic-policy）。
     *
     * 需要的 allocation 键：tunnel_id, loopback_ip, ptp_our(transit), ptp_peer(transit),
     *                       remote_ip, prefix, bandwidth。
     *
     * @param array  $alloc
     * @param string $custTag
     * @return string[]
     */
    public static function greCreate(array $alloc, string $custTag): array
    {
        $n        = (int) $alloc['tunnel_id'];
        $loopIp   = (string) $alloc['loopback_ip'];
        $tranOur  = (string) $alloc['ptp_our'];
        $tranPeer = (string) $alloc['ptp_peer'];
        $remote   = (string) $alloc['remote_ip'];
        $prefix   = self::parsePrefix((string) $alloc['prefix']);

        $lines = [];
        $lines[] = 'system-view';
        // 源 LoopBack /32
        $lines[] = 'interface LoopBack' . $n;
        $lines[] = ' description GRE-SRC-' . $custTag;
        $lines[] = ' ip address ' . $loopIp . ' 255.255.255.255';
        $lines[] = 'quit';
        // Tunnel
        $lines[] = 'interface Tunnel' . $n;
        $lines[] = ' description GRE-to-' . $custTag . '-' . $remote;
        $lines[] = ' mtu 1476';
        $lines[] = ' ip address ' . $tranOur . ' 255.255.255.252';
        $lines[] = ' tunnel-protocol gre';
        $lines[] = ' source ' . $loopIp;
        $lines[] = ' destination ' . $remote;
        $lines[] = ' statistic enable both';
        $lines[] = 'quit';
        // 客户段静态路由 → Tunnel + transit 对端
        $lines[] = 'ip route-static ' . $prefix['net'] . ' ' . $prefix['mask'] . ' Tunnel' . $n . ' ' . $tranPeer;

        // 隧道按客户限速在本设备不可行：Tunnel 接口不支持 qos/traffic-policy；唯一可行的
        // 全局 traffic-policy（ACL 抓客户段）会占用紧张的出向 EACL slice（整机仅 ~1）。
        // **隧道只开通、不在交换机限速**，限速将来走下挂 RouteOS。
        // 带宽仅记录（allocation.bandwidth + 回写客户字段）；greTrafficPolicy() 保留未调用，
        // 将来若启用隧道限速可恢复（注意它占 EACL slice 的约束）。
        $lines[] = 'return';
        return $lines;
    }

    // ======================================================================
    // traffic-policy—— 拆成 def（classifier/behavior/policy）与 apply（套接口）
    // ======================================================================

    /**
     * 仅 traffic classifier/behavior/policy 定义部分（不含 system-view/return）。
     */
    private static function trafficPolicyDefBody(array $alloc): array
    {
        $sid      = (int) $alloc['serviceid'];
        $cls      = self::classifierName($sid);
        $beh      = self::behaviorName($sid);
        $pol      = self::policyName($sid);
        $cirKbps  = self::bandwidthToCirKbps((string) ($alloc['bandwidth'] ?? '100M'));

        $lines = [];
        $lines[] = 'traffic classifier ' . $cls . ' operator or';
        $lines[] = ' if-match any';
        $lines[] = 'quit';
        $lines[] = 'traffic behavior ' . $beh;
        $lines[] = ' car cir ' . $cirKbps . ' pir ' . $cirKbps . ' green pass yellow pass red discard';
        $lines[] = 'quit';
        $lines[] = 'traffic policy ' . $pol;
        $lines[] = ' classifier ' . $cls . ' behavior ' . $beh;
        $lines[] = 'quit';
        return $lines;
    }

    /**
     * 仅「在某接口上套 traffic-policy（inbound+outbound）」部分。
     */
    private static function trafficPolicyApplyBody(string $iface, array $alloc): array
    {
        $pol = self::policyName((int) $alloc['serviceid']);
        return [
            'interface ' . $iface,
            ' traffic-policy ' . $pol . ' inbound',
            ' traffic-policy ' . $pol . ' outbound',
            'quit',
        ];
    }

    /**
     * GRE 限速：华为 Tunnel 接口不支持 traffic-policy 接口应用，故改为
     * 「高级 ACL 匹配客户网段 + traffic-policy 全局应用（global inbound/outbound）」。
     * GRE 流量经 Tunnel 封装后在物理口是混合流量，必须用 ACL 显式分类（不能像 XC 那样靠 Vlanif 隐式分类 if-match any）。
     * 需要 allocation 键：serviceid, acl_id, prefix, bandwidth。
     * @return string[]（在 system-view 下；不含 system-view/return）
     */
    private static function greTrafficPolicy(array $alloc): array
    {
        $sid   = (int) $alloc['serviceid'];
        $cls   = self::classifierName($sid);
        $beh   = self::behaviorName($sid);
        $pol   = self::policyName($sid);
        $aclId = (int) $alloc['acl_id'];
        $cir   = self::bandwidthToCirKbps((string) ($alloc['bandwidth'] ?? '100M'));
        $p     = self::parsePrefix((string) $alloc['prefix']);
        $net   = $p['net'];
        $wild  = $p['wildcard'];

        return [
            // 高级 ACL：双向匹配客户网段（出方向=源，入方向=目的）
            'acl number ' . $aclId,
            ' rule 5 permit ip source ' . $net . ' ' . $wild,
            ' rule 10 permit ip destination ' . $net . ' ' . $wild,
            'quit',
            // 分类器按 ACL 匹配（不是 XC 的 if-match any）
            'traffic classifier ' . $cls . ' operator or',
            ' if-match acl ' . $aclId,
            'quit',
            'traffic behavior ' . $beh,
            ' car cir ' . $cir . ' pir ' . $cir . ' green pass yellow pass red discard',
            'quit',
            'traffic policy ' . $pol,
            ' classifier ' . $cls . ' behavior ' . $beh,
            'quit',
            // 全局应用（不进 Tunnel 接口）。方向语义待真机校准；先双向都限。
            'traffic-policy ' . $pol . ' global inbound',
            'traffic-policy ' . $pol . ' global outbound',
            // 备选（真机若 global 不支持/有上限，再改）：套到客户流量必经的上联物理口
            //   interface <上联口>; traffic-policy <pol> inbound/outbound；口名由 addon 配置提供。
        ];
    }

    /**
     * 仅改带宽：重下 classifier/behavior/policy（car 值变）。
     * VRP 下，traffic behavior 已存在时再下 `car` 会覆盖参数（幂等），无需先 undo。
     * ChangePackage 用。接口绑定不变，故不重套接口。
     *
     * @return string[] system-view ... return
     */
    public static function changeBandwidth(array $alloc): array
    {
        $cir = self::bandwidthToCirKbps((string) ($alloc['bandwidth'] ?? '100M'));
        // XC：重下端口 qos lr 的 cir（覆盖原值，幂等）。
        if (($alloc['delivery_type'] ?? '') === 'xc' && !empty($alloc['port'])) {
            return [
                'system-view',
                'interface ' . (string) $alloc['port'],
                ' qos lr inbound cir ' . $cir,
                ' qos lr outbound cir ' . $cir,
                'quit',
                'return',
            ];
        }
        // 隧道（GRE 等）：本轮设备不限速 → 无设备变更（带宽仅记录，DB 由 ChangePackage 更新）。
        return [];
    }

    // ======================================================================
    // 拆除—— undo 顺序：路由 → 解绑 policy → 删接口 → 删 policy/behavior/classifier → 删 VLAN
    // ======================================================================

    /**
     * XC 拆除（Terminate）。
     * @return string[]
     */
    public static function xcTeardown(array $alloc): array
    {
        $vid     = (int) $alloc['vlan_id'];
        $ptpPeer = (string) $alloc['ptp_peer'];
        $port    = (string) $alloc['port'];
        $prefix  = self::parsePrefix((string) $alloc['prefix']);
        $sid     = (int) $alloc['serviceid'];

        $lines = [];
        $lines[] = 'system-view';
        // 1) 撤客户段静态路由
        $lines[] = 'undo ip route-static ' . $prefix['net'] . ' ' . $prefix['mask'] . ' ' . $ptpPeer;
        // 2) 端口：撤 qos lr 限速 + 恢复 default（先把口从 VLAN 拿出来）
        if ($port !== '') {
            $lines[] = 'interface ' . $port;
            $lines[] = ' undo qos lr inbound';
            $lines[] = ' undo qos lr outbound';
            $lines[] = ' undo port default vlan';
            $lines[] = ' undo description';
            $lines[] = 'quit';
        }
        // 3) 删 Vlanif（XC 限速已改 qos lr 在端口上，无 traffic-policy 可解绑）
        $lines[] = 'undo interface Vlanif' . $vid;
        // 4) 删 VLAN
        $lines[] = 'undo vlan ' . $vid;
        $lines[] = 'return';
        return $lines;
    }

    /**
     * GRE 拆除（Terminate）。
     * @return string[]
     */
    public static function greTeardown(array $alloc): array
    {
        $n        = (int) $alloc['tunnel_id'];
        $tranPeer = (string) $alloc['ptp_peer'];
        $prefix   = self::parsePrefix((string) $alloc['prefix']);
        $sid      = (int) $alloc['serviceid'];

        $lines = [];
        $lines[] = 'system-view';
        // 1) 撤路由
        $lines[] = 'undo ip route-static ' . $prefix['net'] . ' ' . $prefix['mask'] . ' Tunnel' . $n . ' ' . $tranPeer;
        // 2) 删 Tunnel 与 LoopBack（本轮隧道不下限速 → 无 acl/traffic-policy 可拆）
        $lines[] = 'undo interface Tunnel' . $n;
        $lines[] = 'undo interface LoopBack' . $n;
        $lines[] = 'return';
        return $lines;
    }

    // ======================================================================
    // 暂停 / 恢复—— 默认用「撤/重下静态路由」（可逆、不动接口骨架）
    // ======================================================================

    /**
     * Suspend：撤客户段静态路由（停对外可达，保留 VLAN/接口/Tunnel 骨架）。
     * @return string[]
     */
    public static function suspend(array $alloc): array
    {
        $route = self::staticRouteLine($alloc, false);
        return ['system-view', 'undo ' . $route, 'return'];
    }

    /**
     * Unsuspend：重下客户段静态路由。
     * @return string[]
     */
    public static function unsuspend(array $alloc): array
    {
        $route = self::staticRouteLine($alloc, false);
        return ['system-view', $route, 'return'];
    }

    /**
     * 生成该服务的 ip route-static 行（不含 undo 前缀）。XC → PTP 对端；GRE → Tunnel + transit 对端。
     *
     * @param array $alloc
     * @param bool  $unusedReserved 占位（保持签名清晰）
     * @return string
     */
    public static function staticRouteLine(array $alloc, bool $unusedReserved = false): string
    {
        $prefix = self::parsePrefix((string) $alloc['prefix']);
        if (($alloc['delivery_type'] ?? '') === 'gre') {
            $n = (int) $alloc['tunnel_id'];
            return 'ip route-static ' . $prefix['net'] . ' ' . $prefix['mask']
                . ' Tunnel' . $n . ' ' . (string) $alloc['ptp_peer'];
        }
        return 'ip route-static ' . $prefix['net'] . ' ' . $prefix['mask'] . ' ' . (string) $alloc['ptp_peer'];
    }

    // ======================================================================
    // GRE 改对端（客户区）—— 只改 Tunnel 的 destination
    // ======================================================================

    /**
     * 只改 Tunnel destination（幂等）。同时更新 description 里的远端串。
     * @return string[]
     */
    public static function greChangeRemote(array $alloc, string $newRemote, string $custTag): array
    {
        $n = (int) $alloc['tunnel_id'];
        return [
            'system-view',
            'interface Tunnel' . $n,
            ' description GRE-to-' . $custTag . '-' . $newRemote,
            ' destination ' . $newRemote,
            'quit',
            'return',
        ];
    }

    // ======================================================================
    // display 预检 / 校验回读—— 只读，不进 system-view
    // ======================================================================

    /**
     * 幂等预检 / 校验用的 display 命令集合（按交付类型）。
     * 返回 ['key'=>'display 命令'] 便于上层逐条解析。
     */
    public static function verifyCommands(array $alloc): array
    {
        $prefix = self::parsePrefix((string) $alloc['prefix']);
        $cmds   = [
            // 带掩码长 = 精确单条查询：客户段删除后输出为空，避免华为最长匹配回退到池聚合(/24 NULL0)被误判残留。
            'route' => 'display ip routing-table ' . $prefix['net'] . ' ' . $prefix['len'],
            // 注：XC 用端口 qos lr、隧道不限速 → 不再查 traffic-policy applied-record。
        ];
        if (($alloc['delivery_type'] ?? '') === 'gre') {
            $n = (int) $alloc['tunnel_id'];
            $cmds['iface'] = 'display interface Tunnel' . $n;
        } else {
            $vid = (int) $alloc['vlan_id'];
            $cmds['vlan']  = 'display vlan ' . $vid;
            $cmds['iface'] = 'display interface Vlanif' . $vid;
        }
        return $cmds;
    }

    /**
     * 单条 display version（TestConnection 用）。
     */
    public static function displayVersion(): string
    {
        return 'display version';
    }

    /**
     * 拆除后「无残留」校验命令（与 verifyCommands 同，调用方判断应为空/不命中）。
     */
    public static function teardownVerifyCommands(array $alloc): array
    {
        return self::verifyCommands($alloc);
    }

    // ======================================================================
    // 内部：解析交付前缀
    // ======================================================================

    /**
     * 'a.b.c.d/NN' → ['net'=>'a.b.c.d', 'mask'=>'255.255.255.x', 'len'=>NN]。
     * @throws \RuntimeException
     */
    public static function parsePrefix(string $cidr): array
    {
        $p   = Ipam::parseCidr($cidr);
        return [
            'net'      => $p['net'],
            'len'      => $p['len'],
            'mask'     => Ipam::maskLenToDotted($p['len']),
            'wildcard' => Ipam::maskLenToWildcard($p['len']), // 高级 ACL 反掩码（/28 → 0.0.0.15）
        ];
    }
}
