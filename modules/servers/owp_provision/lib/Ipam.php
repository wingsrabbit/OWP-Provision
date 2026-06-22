<?php
/**
 * IP-Delivery — Ipam.php
 * ----------------------------------------------------------------------------
 * 资源池分配 / 回收。VLAN / PTP(/30) / prefix(更具体段) / port / loopback(/32) /
 * tunnel-id。全部在 DB 事务 + 行锁（lockForUpdate）内完成，避免并发撞号。
 *
 * 设计要点：
 *  - 池定义存在 mod_owp_provision_pools；已分配存在 mod_owp_provision_allocations。
 *  - 「在用」判定 = allocations 表（未 terminated）的占用 ∪ 硬编码保留（HW_RESERVED_*）。
 *    保留项是只读常量，不是「密钥」。
 *  - 一个 serviceid 一条 allocation 记录（unique）。重复开通 → 复用已有记录（幂等）。
 *  - 全部用纯 PHP 做 CIDR 数学（ip2long/long2ip），不依赖 GMP/外部库。
 *  - 仅处理 IPv4（本交付模型 v4-only；v6 不在范围）。
 *
 * 不确定 $params/池值格式时：先 logModuleCall + var_export 落一遍真实结构再取值。
 *
 * @package OwpProvision
 * @target  WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Ipam
{
    /**
     * 通用保留 VLAN（VLAN 1 普遍保留）。**设备已在用的其它 VLAN，请用「池范围」避开，
     * 或在该池 meta.exclude 里声明** —— 代码不硬编码任何具体部署的占用值（白标）。
     */
    private const HW_RESERVED_VLANS = [1];

    /**
     * 代码层不硬编码任何具体部署的在用资源。已被占用的 PTP /30、loopback /32、tunnel 编号，
     * 请在对应池的 meta.exclude（ptp/loopback）声明，或用池范围（vlan/tunnel）避开。见 README。
     */
    private const HW_RESERVED_PTP30 = [];
    private const HW_RESERVED_LOOPBACK32 = [];
    private const HW_RESERVED_TUNNEL_IDS = [];

    /** 自动分配 tunnel-id 的默认区间（建议 1000+）。 */
    private const TUNNEL_ID_MIN = 1000;
    private const TUNNEL_ID_MAX = 1999;

    /** 自动分配高级 ACL 号的默认区间（华为高级 ACL = 3000–3999；GRE 限速用）。可用 kind=acl 池覆盖。 */
    private const ACL_ID_MIN = 3000;
    private const ACL_ID_MAX = 3999;
    private const HW_RESERVED_ACL = [];

    // ----------------------------------------------------------------------
    // 公共入口
    // ----------------------------------------------------------------------

    /**
     * 取某 serviceid 的分配记录（stdClass 或 null）。
     */
    public static function getAllocation(int $serviceId)
    {
        return Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)->first();
    }

    /**
     * 为一个 XC 服务分配资源（事务 + 行锁）。幂等：已有 active/suspended 记录则直接返回。
     *
     * @param int         $serviceId
     * @param int         $deviceId   交付到的设备（节点）id
     * @param string      $custTag    description 用标签
     * @param string      $prefixSize 交付掩码，如 '/28'（含或不含前导 / 都接受）
     * @param string      $bandwidth  限速档，如 '100M'
     * @param string|null $wantPort   管理员指定端口（可空=自动）
     * @return array 分配结果（与 allocations 行同结构的关联数组）
     * @throws \RuntimeException 池不足/冲突时
     */
    public static function allocateXc(int $serviceId, int $deviceId, string $custTag, string $prefixSize, string $bandwidth, ?string $wantPort = null): array
    {
        return Capsule::connection()->transaction(function () use ($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $wantPort) {
            $existing = self::lockAllocation($serviceId);
            if ($existing && $existing->status !== 'terminated') {
                return (array) $existing; // 幂等复用
            }

            $maskLen = self::normalizeMaskLen($prefixSize);
            $vlan    = self::pickFreeVlan($deviceId);
            $ptp     = self::pickFreePtp30($deviceId);
            $prefix  = self::pickFreePrefix($deviceId, $maskLen);
            $port    = self::pickFreePort($deviceId, $wantPort);

            $row = [
                'serviceid'     => $serviceId,
                'device_id'     => $deviceId,
                'delivery_type' => 'xc',
                'vlan_id'       => $vlan,
                'ptp_net'       => $ptp['net'],
                'ptp_our'       => $ptp['our'],
                'ptp_peer'      => $ptp['peer'],
                'prefix'        => $prefix,
                'port'          => $port,
                'bandwidth'     => $bandwidth,
                'policy_name'   => self::policyName($serviceId),
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ];
            self::upsertAllocation($serviceId, $row, (bool) $existing);
            return $row;
        });
    }

    /**
     * 为一个 GRE 服务分配资源（事务 + 行锁）。需要客户对端 IP（已在上层校验合法）。
     *
     * @param int    $serviceId
     * @param int    $deviceId   交付到的设备（节点）id
     * @param string $custTag
     * @param string $prefixSize
     * @param string $bandwidth
     * @param string $remoteIp   客户对端公网 IPv4
     * @return array
     * @throws \RuntimeException
     */
    public static function allocateGre(int $serviceId, int $deviceId, string $custTag, string $prefixSize, string $bandwidth, string $remoteIp): array
    {
        return Capsule::connection()->transaction(function () use ($serviceId, $deviceId, $custTag, $prefixSize, $bandwidth, $remoteIp) {
            $existing = self::lockAllocation($serviceId);
            if ($existing && $existing->status !== 'terminated') {
                // 幂等复用；但允许刷新 remote_ip（客户可能在下单后改过）
                if ($remoteIp !== '' && $existing->remote_ip !== $remoteIp) {
                    Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)
                        ->update(['remote_ip' => $remoteIp, 'updated_at' => date('Y-m-d H:i:s')]);
                    $existing->remote_ip = $remoteIp;
                }
                return (array) $existing;
            }

            $maskLen  = self::normalizeMaskLen($prefixSize);
            $ptp      = self::pickFreePtp30($deviceId);
            $prefix   = self::pickFreePrefix($deviceId, $maskLen);
            $loopback = self::pickFreeLoopback32($deviceId);
            $tunnelId = self::pickFreeTunnelId($deviceId);
            $aclId    = self::pickFreeAclId($deviceId); // GRE 限速用高级 ACL

            $row = [
                'serviceid'     => $serviceId,
                'device_id'     => $deviceId,
                'delivery_type' => 'gre',
                'ptp_net'       => $ptp['net'],
                'ptp_our'       => $ptp['our'],   // = transit our
                'ptp_peer'      => $ptp['peer'],  // = transit peer
                'prefix'        => $prefix,
                'tunnel_id'     => $tunnelId,
                'loopback_ip'   => $loopback,
                'tunnel_source' => $loopback,
                'acl_id'        => $aclId,
                'remote_ip'     => $remoteIp,
                'bandwidth'     => $bandwidth,
                'policy_name'   => self::policyName($serviceId),
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ];
            self::upsertAllocation($serviceId, $row, (bool) $existing);
            return $row;
        });
    }

    /**
     * 标记状态（active/suspended）。不释放资源。
     */
    public static function setStatus(int $serviceId, string $status): void
    {
        Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 释放：把该 serviceid 的占用标 terminated，资源即回池（因为「在用」判定排除 terminated）。
     * 保留记录便于审计；不物理删除。
     */
    public static function release(int $serviceId): void
    {
        Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)
            ->update(['status' => 'terminated', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 更新 GRE 对端 IP（客户区改对端用）。返回更新后的分配数组。
     */
    public static function updateRemoteIp(int $serviceId, string $remoteIp): array
    {
        return Capsule::connection()->transaction(function () use ($serviceId, $remoteIp) {
            $alloc = self::lockAllocation($serviceId);
            if (!$alloc) {
                throw new \RuntimeException('该服务无分配记录，无法改对端。');
            }
            Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)->update([
                'remote_ip'         => $remoteIp,
                'remote_changed_at' => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
            $alloc->remote_ip = $remoteIp;
            return (array) $alloc;
        });
    }

    // ----------------------------------------------------------------------
    // 行锁 / upsert 帮手
    // ----------------------------------------------------------------------

    /** 在事务内对该 serviceid 的 allocation 加行锁（若存在）。 */
    private static function lockAllocation(int $serviceId)
    {
        return Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)
            ->lockForUpdate()->first();
    }

    private static function upsertAllocation(int $serviceId, array $row, bool $exists): void
    {
        if ($exists) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            unset($row['created_at']);
            Capsule::table(Schema::T_ALLOCATIONS)->where('serviceid', $serviceId)->update($row);
        } else {
            Capsule::table(Schema::T_ALLOCATIONS)->insert($row);
        }
    }

    /** traffic-policy 命名（serviceid 唯一化）。classifier/behavior 同源派生（见 Templates）。 */
    public static function policyName(int $serviceId): string
    {
        return 'tp-' . $serviceId;
    }

    // ----------------------------------------------------------------------
    // 各资源的「从清单挑一条空闲」逻辑（清单式 IPAM；均在事务内被调用）。
    // 占用由 Resources 实时从 allocations 算（freeItems 已排除占用/停用条目）。
    // ----------------------------------------------------------------------

    /**
     * VLAN：从清单挑最小未占（排除通用保留 VLAN 1）。
     * @throws \RuntimeException
     */
    public static function pickFreeVlan(int $deviceId): int
    {
        foreach (Resources::freeItems($deviceId, 'vlan') as $r) {
            $vid = (int) $r->value;
            if (!in_array($vid, self::HW_RESERVED_VLANS, true)) {
                return $vid;
            }
        }
        throw new \RuntimeException('无空闲 VLAN 资源。请在 IPAM 页为该设备添加 VLAN 条目。');
    }

    /**
     * PTP：从清单挑一条空闲 ptp 子段（任意掩码）。our/peer 按其掩码算：
     * /31 → our=base、peer=base+1（RFC3021）；其余 → our=base+1、peer=base+2（首两可用）。
     * 返回 ['net'=>'a.b.c.d/NN','our'=>..,'peer'=>..]。
     * @throws \RuntimeException
     */
    public static function pickFreePtp30(int $deviceId): array
    {
        foreach (Resources::freeItems($deviceId, 'ptp') as $r) {
            $base = ip2long((string) $r->value);
            if ($base === false) {
                continue;
            }
            $mask = $r->mask !== null ? (int) $r->mask : 30;
            if ($mask >= 31) {
                $our  = long2ip($base);
                $peer = long2ip($base + 1);
            } else {
                $our  = long2ip($base + 1);
                $peer = long2ip($base + 2);
            }
            return ['net' => $r->value . '/' . $mask, 'our' => $our, 'peer' => $peer];
        }
        throw new \RuntimeException('无空闲 PTP 资源。请在 IPAM 页为该设备添加 PTP 子段。');
    }

    /**
     * prefix：从清单挑一条**掩码等于订单交付掩码**的空闲交付段（占用/重叠由 freeItems 排除）。
     * @param int $deviceId 设备（节点）id
     * @param int $maskLen  订单交付掩码长度
     * @return string 'a.b.c.d/NN'
     * @throws \RuntimeException
     */
    public static function pickFreePrefix(int $deviceId, int $maskLen): string
    {
        foreach (Resources::freeItems($deviceId, 'prefix') as $r) {
            if ((int) ($r->mask ?? 0) === $maskLen) {
                return $r->value . '/' . $maskLen;
            }
        }
        throw new \RuntimeException('无空闲 /' . $maskLen . ' 交付前缀。请在 IPAM 页按 /' . $maskLen . ' 切分母段或手动添加该掩码的交付段。');
    }

    /**
     * port：客户/管理员指定则校验「在清单内且空闲」后用；否则取首个空闲端口条目。
     * 端口名规范化为设备全名。**在 allocateXc 的事务内调用**。
     * @throws \RuntimeException
     */
    public static function pickFreePort(int $deviceId, ?string $wantPort): string
    {
        $free = [];
        foreach (Resources::freeItems($deviceId, 'port') as $r) {
            $free[] = self::normalizePort((string) $r->value);
        }
        if ($wantPort !== null && trim($wantPort) !== '') {
            $norm = self::normalizePort(trim($wantPort));
            $all  = [];
            foreach (Resources::listByDevice($deviceId, 'port') as $r) {
                $all[] = self::normalizePort((string) $r->value);
            }
            if (!in_array($norm, $all, true)) {
                throw new \RuntimeException('所选端口 ' . $norm . ' 不在该设备端口清单内，请重新选择。');
            }
            if (!in_array($norm, $free, true)) {
                throw new \RuntimeException('所选端口 ' . $norm . ' 已被占用或已停用，请重新选择其它端口。');
            }
            return $norm;
        }
        if (!empty($free)) {
            return $free[0];
        }
        throw new \RuntimeException('无空闲 XC 物理端口。请在 IPAM 页为该设备添加端口条目或在下单时指定。');
    }

    /**
     * 当前空闲端口名（供下单页 AJAX）。按设备读清单中「空闲端口条目」（占用由 allocations 实时算）。
     * @return string[]
     */
    public static function freePorts(int $deviceId): array
    {
        $out = [];
        foreach (Resources::freeItems($deviceId, 'port') as $r) {
            $norm = self::normalizePort((string) $r->value);
            if (!in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /**
     * loopback：从清单挑一条空闲条目（返回裸 IP；loopback 配 255.255.255.255）。
     * @throws \RuntimeException
     */
    public static function pickFreeLoopback32(int $deviceId): string
    {
        foreach (Resources::freeItems($deviceId, 'loopback') as $r) {
            return (string) $r->value;
        }
        throw new \RuntimeException('无空闲 Loopback 资源。请在 IPAM 页为该设备添加 Loopback 条目。');
    }

    /**
     * tunnel-id：优先从清单挑空闲；该设备无 tunnel 清单条目时回退默认区间（占用从 allocations 算）。
     * @throws \RuntimeException
     */
    public static function pickFreeTunnelId(int $deviceId): int
    {
        if (Resources::countByDevice($deviceId, 'tunnel') > 0) {
            foreach (Resources::freeItems($deviceId, 'tunnel') as $r) {
                $id = (int) $r->value;
                if (!in_array($id, self::HW_RESERVED_TUNNEL_IDS, true)) {
                    return $id;
                }
            }
            throw new \RuntimeException('无空闲 Tunnel-ID 资源。请在 IPAM 页为该设备扩充 tunnel 条目。');
        }
        // 无 tunnel 清单 → 默认区间回退
        $used = array_merge(self::usedTunnelIds($deviceId), self::HW_RESERVED_TUNNEL_IDS);
        for ($id = self::TUNNEL_ID_MIN; $id <= self::TUNNEL_ID_MAX; $id++) {
            if (!in_array($id, $used, true)) {
                return $id;
            }
        }
        throw new \RuntimeException('Tunnel-id 已耗尽。');
    }

    /**
     * 高级 ACL 号：优先清单；无 acl 清单条目时回退默认区间。GRE 限速用（隧道不限速，仍保留分配）。
     * @throws \RuntimeException
     */
    public static function pickFreeAclId(int $deviceId): int
    {
        if (Resources::countByDevice($deviceId, 'acl') > 0) {
            foreach (Resources::freeItems($deviceId, 'acl') as $r) {
                $id = (int) $r->value;
                if (!in_array($id, self::HW_RESERVED_ACL, true)) {
                    return $id;
                }
            }
            throw new \RuntimeException('无空闲高级 ACL 号资源。请在 IPAM 页为该设备扩充 acl 条目。');
        }
        $used = array_merge(self::usedAclIds($deviceId), self::HW_RESERVED_ACL);
        for ($id = self::ACL_ID_MIN; $id <= self::ACL_ID_MAX; $id++) {
            if (!in_array($id, $used, true)) {
                return $id;
            }
        }
        throw new \RuntimeException('高级 ACL 号已耗尽。');
    }

    // ----------------------------------------------------------------------
    // 「已占用」查询（仅 tunnel/acl 默认区间回退用；其余占用判定见 Resources::occupant）
    // ----------------------------------------------------------------------

    /** @return int[] */
    private static function usedTunnelIds(int $deviceId): array
    {
        return array_map('intval', Capsule::table(Schema::T_ALLOCATIONS)
            ->where('device_id', $deviceId)
            ->where('status', '!=', 'terminated')->whereNotNull('tunnel_id')
            ->pluck('tunnel_id')->all());
    }

    /** @return int[] */
    private static function usedAclIds(int $deviceId): array
    {
        return array_map('intval', Capsule::table(Schema::T_ALLOCATIONS)
            ->where('device_id', $deviceId)
            ->where('status', '!=', 'terminated')->whereNotNull('acl_id')
            ->pluck('acl_id')->all());
    }

    // ----------------------------------------------------------------------
    // CIDR / 范围 工具（纯 PHP，IPv4）
    // ----------------------------------------------------------------------

    /** '/28' | '28' | 28 → 28（校验 0..32）。 */
    public static function normalizeMaskLen($prefixSize): int
    {
        $n = (int) ltrim((string) $prefixSize, '/');
        if ($n < 0 || $n > 32) {
            throw new \RuntimeException('非法掩码长度：' . $prefixSize);
        }
        return $n;
    }

    /** 点分掩码：28 → 255.255.255.240。 */
    public static function maskLenToDotted(int $len): string
    {
        if ($len === 0) {
            return '0.0.0.0';
        }
        return long2ip((0xFFFFFFFF << (32 - $len)) & 0xFFFFFFFF);
    }

    /** 反掩码（华为高级 ACL 用）：28 → 0.0.0.15；32 → 0.0.0.0；0 → 255.255.255.255。 */
    public static function maskLenToWildcard(int $len): string
    {
        if ($len >= 32) {
            return '0.0.0.0';
        }
        return long2ip(((1 << (32 - $len)) - 1) & 0xFFFFFFFF);
    }

    /** 'a.b.c.d/NN' → ['net'=>裸IP, 'len'=>NN]。 */
    public static function parseCidr(string $cidr): array
    {
        $parts = explode('/', trim($cidr));
        $ip    = $parts[0];
        $len   = isset($parts[1]) ? (int) $parts[1] : 32;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $len < 0 || $len > 32) {
            throw new \RuntimeException('非法 CIDR：' . $cidr);
        }
        return ['net' => $ip, 'len' => $len];
    }

    /**
     * 把一个母段切成若干 /childLen 子段，返回 CIDR 串数组。
     * 例：splitCidr('100.64.0.0/24', 30) → ['100.64.0.0/30', '100.64.0.4/30', ...]
     * 注意：可能很大（/24 切 /32 = 256 项），调用方按需逐个判断。
     * @return string[]
     */
    public static function splitCidr(string $parentCidr, int $childLen): array
    {
        $p = self::parseCidr($parentCidr);
        if ($childLen < $p['len']) {
            return []; // 子段比母段大，无意义
        }
        $parentLong = ip2long($p['net']) & ((0xFFFFFFFF << (32 - $p['len'])) & 0xFFFFFFFF);
        $step       = 1 << (32 - $childLen);
        $count      = 1 << ($childLen - $p['len']);
        $out        = [];
        for ($i = 0; $i < $count; $i++) {
            $sub   = ($parentLong + $i * $step) & 0xFFFFFFFF;
            $out[] = long2ip($sub) . '/' . $childLen;
        }
        return $out;
    }

    /** 两个 CIDR 是否重叠（含相等/包含）。 */
    public static function cidrOverlap(string $a, string $b): bool
    {
        $pa = self::parseCidr($a);
        $pb = self::parseCidr($b);
        $maskLen = min($pa['len'], $pb['len']);
        $mask    = $maskLen === 0 ? 0 : ((0xFFFFFFFF << (32 - $maskLen)) & 0xFFFFFFFF);
        return ((ip2long($pa['net']) & $mask) === (ip2long($pb['net']) & $mask));
    }

    /**
     * 展开整数范围串 '1000-1100' / '1000,1001,1050-1060' → [1000,1001,...]（升序去重）。
     * 同时用于 VLAN 与 tunnel-id。
     * @return int[]
     */
    public static function expandVlanRange(string $spec): array
    {
        $out = [];
        foreach (array_map('trim', explode(',', $spec)) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            if (strpos($chunk, '-') !== false) {
                [$lo, $hi] = array_map('intval', explode('-', $chunk, 2));
                if ($hi < $lo) {
                    [$lo, $hi] = [$hi, $lo];
                }
                for ($i = $lo; $i <= $hi; $i++) {
                    $out[$i] = true;
                }
            } else {
                $out[(int) $chunk] = true;
            }
        }
        $ids = array_keys($out);
        sort($ids);
        return $ids;
    }

    /**
     * 端口名规范化：接受 'XGE0/0/10' / 'XGigabitEthernet0/0/10' / '10G0/0/10' → 'XGigabitEthernet0/0/10'。
     * 100GE / GE / Eth-Trunk 等其它前缀原样返回（只是去空格）。
     */
    public static function normalizePort(string $port): string
    {
        $p = trim($port);
        // 已是全名
        if (stripos($p, 'XGigabitEthernet') === 0) {
            return 'XGigabitEthernet' . substr($p, strlen('XGigabitEthernet'));
        }
        if (preg_match('#^(?:XGE|10GE|XGigE)\s*(\d+/\d+/\d+)$#i', $p, $m)) {
            return 'XGigabitEthernet' . $m[1];
        }
        if (preg_match('#^100GE\s*(\d+/\d+/\d+)$#i', $p, $m)) {
            return '100GE' . $m[1];
        }
        if (preg_match('#^(?:GE|GigabitEthernet)\s*(\d+/\d+/\d+)$#i', $p, $m)) {
            return 'GigabitEthernet' . $m[1];
        }
        return $p; // 未识别：原样（上层校验时若空闲即用）
    }

    /** 解析 pools.meta（JSON），失败返回空数组。 */
    public static function decodeMeta($meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (!is_string($meta) || $meta === '') {
            return [];
        }
        $d = json_decode($meta, true);
        return is_array($d) ? $d : [];
    }

    /**
     * 校验是否合法公网 IPv4（拒私网/保留）。GRE 对端校验用。
     */
    public static function isPublicIpv4(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
