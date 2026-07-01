<?php
/**
 * OWP Provision — lib/Pools.php  (v2.8 · IPAM 池组 + 按需对齐分配)
 * ----------------------------------------------------------------------------
 * P11 新模型：admin 只管「池组」——往组里加**原始母段**（/25、/27… 任意混搭，多条），设**对外允许的
 * 交付掩码范围**（deliver_min~deliver_max）。切割/合并**全自动、不预切、不物化碎片**：
 *
 *   某组空闲 = 组所有原始母段 − 全系统所有在用 allocation 的 prefix（CIDR 减法，实时算）。
 *   下单要 /N（须在范围内）→ 在母段里找**任一 /N 对齐且整块全空闲**的区域，命中即分配。
 *   释放（Terminate）= 删该 allocation，空闲自动恢复——无碎片、无需 buddy 合并。
 *
 * 这天然支持「两块相邻空闲 /27 拼成的 /26」直接命中（对齐且无占用），解决旧清单式 carve 单向切碎的痛点。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Pools
{
    // ---------------------------------------------------------------- 组 CRUD
    public static function groups(string $purpose = ''): array
    {
        $q = Capsule::table(Schema::T_POOL_GROUPS)->orderBy('id');
        if ($purpose !== '') {
            $q->where('purpose', $purpose);
        }
        return $q->get()->all();
    }

    public static function group(int $id): ?object
    {
        return Capsule::table(Schema::T_POOL_GROUPS)->where('id', $id)->first() ?: null;
    }

    public static function addGroup(string $name, string $purpose, ?int $lineId, ?int $deviceId, int $min, int $max): int
    {
        $now = date('Y-m-d H:i:s');
        $purpose = self::normalizePurpose($purpose);
        return (int) Capsule::table(Schema::T_POOL_GROUPS)->insertGetId([
            'name' => $name, 'purpose' => $purpose,
            'line_id' => $lineId ?: null, 'device_id' => $deviceId ?: null,
            'deliver_min' => $min, 'deliver_max' => $max, 'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function normalizePurpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));
        return in_array($purpose, ['delivery', 'vpn', 'ipv6'], true) ? $purpose : 'delivery';
    }

    public static function updateGroup(int $id, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(Schema::T_POOL_GROUPS)->where('id', $id)->update($fields);
    }

    public static function deleteGroup(int $id): void
    {
        Capsule::table(Schema::T_POOL_BLOCKS)->where('group_id', $id)->delete();
        Capsule::table(Schema::T_POOL_GROUPS)->where('id', $id)->delete();
    }

    // -------------------------------------------------------------- 母段 CRUD
    public static function blocks(int $groupId): array
    {
        return Capsule::table(Schema::T_POOL_BLOCKS)->where('group_id', $groupId)->orderBy('id')->get()->all();
    }

    public static function addBlock(int $groupId, string $cidr): int
    {
        $group = self::group($groupId);
        if (!$group) {
            throw new \RuntimeException('池组不存在：#' . $groupId);
        }
        if ((string) ($group->purpose ?? 'delivery') === 'ipv6') {
            $canon = self::canonicalIpv6Cidr($cidr);
        } else {
            $p   = Ipam::parseCidr($cidr); // 校验 + 规范网络地址
            $net = long2ip(ip2long($p['net']) & ((0xFFFFFFFF << (32 - $p['len'])) & 0xFFFFFFFF));
            $canon = $net . '/' . $p['len'];
        }
        $now = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_POOL_BLOCKS)->insertGetId([
            'group_id' => $groupId, 'cidr' => $canon,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function deleteBlock(int $id): void
    {
        Capsule::table(Schema::T_POOL_BLOCKS)->where('id', $id)->delete();
    }

    // ------------------------------------------------------------ 选组 + 分配
    /**
     * 找交付池组：有 line_id 时只匹配线路池；无 line_id 时只匹配设备级通用池。
     * 这样服务器产品走线路池，GRE/XC 这类无线路上下文的交付不会误用线路专属池。
     */
    public static function findDeliveryGroup(?int $deviceId, ?int $lineId = null, ?int $groupId = null): ?object
    {
        if ($groupId && $groupId > 0) {
            $g = self::group($groupId);
            return ($g && (string) $g->purpose === 'delivery' && (int) $g->enabled === 1) ? $g : null;
        }
        $q = Capsule::table(Schema::T_POOL_GROUPS)->where('purpose', 'delivery')->where('enabled', 1);
        if ($lineId) {
            $q->where('line_id', $lineId);
        } elseif ($deviceId) {
            $q->where('device_id', $deviceId)->whereNull('line_id');
        } else {
            return null;
        }
        return $q->orderBy('id')->first() ?: null;
    }

    /** VPN 客户 /32 池组（purpose=vpn，按 ROS device_id）。 */
    public static function findVpnGroup(int $rosDeviceId): ?object
    {
        return Capsule::table(Schema::T_POOL_GROUPS)->where('purpose', 'vpn')->where('enabled', 1)
            ->where('device_id', $rosDeviceId)->orderBy('id')->first() ?: null;
    }

    /** IPv6 pool group: explicit project binding wins, then line, then device. */
    public static function findIpv6Group(?int $deviceId, ?int $lineId = null, ?int $groupId = null): ?object
    {
        if ($groupId && $groupId > 0) {
            $g = self::group($groupId);
            return ($g && (string) $g->purpose === 'ipv6' && (int) $g->enabled === 1) ? $g : null;
        }
        $q = Capsule::table(Schema::T_POOL_GROUPS)->where('purpose', 'ipv6')->where('enabled', 1);
        if ($lineId) {
            $q->where('line_id', $lineId);
        } elseif ($deviceId) {
            $q->where('device_id', $deviceId)->whereNull('line_id');
        } else {
            return null;
        }
        return $q->orderBy('id')->first() ?: null;
    }

    /**
     * 从池组按需分配一个**对齐 /maskLen**（不预切；free = 母段 − 全系统在用 allocation）。
     * best-fit：母段按掩码长度降序（小段优先）以保大段完整。返回 'net/maskLen'。
     *
     * @param array<int,string> $exclude 额外要避开的 CIDR（如该 ROS 本端地址 /32）
     * @throws \RuntimeException 越界 / 无空闲
     */
    public static function allocate(object $group, int $maskLen, array $exclude = []): string
    {
        $min = (int) $group->deliver_min;
        $max = (int) $group->deliver_max;
        if ($maskLen < $min || $maskLen > $max) {
            throw new \RuntimeException('交付掩码 /' . $maskLen . ' 超出池组「' . (string) $group->name . '」允许范围 /' . $min . '~/' . $max . '。');
        }
        $blocks = self::blocks((int) $group->id);
        if (empty($blocks)) {
            throw new \RuntimeException('池组「' . (string) $group->name . '」未配置母段，请先在后台添加。');
        }
        if ((string) ($group->purpose ?? 'delivery') === 'vpn') {
            $taken = array_merge(
                self::activeVpnPrefixes((int) ($group->device_id ?? 0)),
                self::reservedVpnPrefixes($blocks),
                $exclude
            );
        } else {
            $taken = array_merge(self::activePrefixes(), $exclude); // 全系统在用 + 额外排除
        }
        // best-fit：小母段优先（掩码长度大者先），保大段完整
        usort($blocks, static function ($a, $b) {
            return Ipam::parseCidr((string) $b->cidr)['len'] <=> Ipam::parseCidr((string) $a->cidr)['len'];
        });
        foreach ($blocks as $blk) {
            $blkLen = Ipam::parseCidr((string) $blk->cidr)['len'];
            if ($maskLen < $blkLen) {
                continue; // 请求块比母段还大，跳过
            }
            foreach (Ipam::splitCidr((string) $blk->cidr, $maskLen) as $cand) {
                $free = true;
                foreach ($taken as $t) {
                    if (Ipam::cidrOverlap($cand, $t)) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    return $cand;
                }
            }
        }
        throw new \RuntimeException('池组「' . (string) $group->name . '」无可分配的空闲 /' . $maskLen . '（已满/被占用）。');
    }

    /**
     * Allocate an IPv6 prefix from an ipv6 pool group. The expected dedicated
     * use case is /64 blocks from /48 or /56 parents, so enumeration is capped.
     *
     * @param array<int,string> $exclude extra IPv6 CIDRs to avoid
     */
    public static function allocateIpv6(object $group, int $maskLen = 64, array $exclude = []): string
    {
        if ((string) ($group->purpose ?? '') !== 'ipv6') {
            throw new \RuntimeException('池组「' . (string) $group->name . '」不是 IPv6 池组。');
        }
        $min = (int) $group->deliver_min;
        $max = (int) $group->deliver_max;
        if ($maskLen < $min || $maskLen > $max) {
            throw new \RuntimeException('IPv6 交付掩码 /' . $maskLen . ' 超出池组「' . (string) $group->name . '」允许范围 /' . $min . '~/' . $max . '。');
        }
        $blocks = self::blocks((int) $group->id);
        if (empty($blocks)) {
            throw new \RuntimeException('IPv6 池组「' . (string) $group->name . '」未配置母段。');
        }
        $taken = array_merge(self::activeIpv6Prefixes(), $exclude);
        usort($blocks, static function ($a, $b) {
            return self::parseIpv6Cidr((string) $b->cidr)['len'] <=> self::parseIpv6Cidr((string) $a->cidr)['len'];
        });
        foreach ($blocks as $blk) {
            $p = self::parseIpv6Cidr((string) $blk->cidr);
            if ($maskLen < $p['len']) {
                continue;
            }
            $diff = $maskLen - (int) $p['len'];
            if ($diff > 20) {
                throw new \RuntimeException('IPv6 母段 ' . (string) $blk->cidr . ' 切 /' . $maskLen . ' 过大，请先拆成较小母段（最多枚举 2^20 个子段）。');
            }
            $count = 1 << $diff;
            $candidate = $p['bin'];
            for ($i = 0; $i < $count; $i++) {
                $cidr = inet_ntop($candidate) . '/' . $maskLen;
                $free = true;
                foreach ($taken as $t) {
                    if (self::ipv6CidrOverlap($cidr, (string) $t)) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    return self::canonicalIpv6Cidr($cidr);
                }
                $candidate = self::ipv6AddSubnet($candidate, $maskLen);
            }
        }
        throw new \RuntimeException('IPv6 池组「' . (string) $group->name . '」无空闲 /' . $maskLen . '。');
    }

    /** 全系统在用 allocation 的交付 prefix（status != terminated，prefix 非空）。 */
    private static function activePrefixes(): array
    {
        $out = [];
        foreach (Capsule::table(Schema::T_ALLOCATIONS)->where('status', '!=', 'terminated')
                     ->whereNotNull('prefix')->where('prefix', '!=', '')->pluck('prefix') as $p) {
            $s = trim((string) $p);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** 全系统在用 IPv6 prefixes（status != terminated）。 */
    private static function activeIpv6Prefixes(): array
    {
        $out = [];
        if (!Capsule::schema()->hasTable(Schema::T_IPV6_ALLOC)) {
            return $out;
        }
        foreach (Capsule::table(Schema::T_IPV6_ALLOC)->where('status', '!=', 'terminated')
                     ->whereNotNull('cidr')->where('cidr', '!=', '')->pluck('cidr') as $p) {
            $s = trim((string) $p);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** 指定 ROS 设备在用的 VPN 客户 /32。 */
    private static function activeVpnPrefixes(int $deviceId): array
    {
        if ($deviceId <= 0) {
            return [];
        }
        $out = [];
        foreach (Capsule::table(Schema::T_ALLOCATIONS)->where('status', '!=', 'terminated')
                     ->where('vpn_device_id', $deviceId)
                     ->whereNotNull('vpn_ip')->where('vpn_ip', '!=', '')->pluck('vpn_ip') as $p) {
            $s = trim((string) $p);
            if ($s === '') {
                continue;
            }
            $out[] = strpos($s, '/') === false ? $s . '/32' : $s;
        }
        return $out;
    }

    /** VPN 客户池保留每个传统网段的网络地址和广播地址，与 setupVpnPool() 的下发范围保持一致。 */
    private static function reservedVpnPrefixes(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $blk) {
            try {
                $p = Ipam::parseCidr((string) $blk->cidr);
                $len = (int) $p['len'];
                if ($len > 30) {
                    continue;
                }
                $base = ip2long($p['net']) & ((0xFFFFFFFF << (32 - $len)) & 0xFFFFFFFF);
                $size = 1 << (32 - $len);
                $out[] = long2ip($base) . '/32';
                $out[] = long2ip($base + $size - 1) . '/32';
            } catch (\Throwable $e) {
            }
        }
        return $out;
    }

    public static function canonicalIpv6Cidr(string $cidr): string
    {
        $p = self::parseIpv6Cidr($cidr);
        return inet_ntop($p['bin']) . '/' . $p['len'];
    }

    /** @return array{bin:string,len:int} */
    private static function parseIpv6Cidr(string $cidr): array
    {
        $parts = explode('/', trim($cidr), 2);
        $ip = $parts[0] ?? '';
        $len = isset($parts[1]) ? (int) $parts[1] : 128;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false || $len < 0 || $len > 128) {
            throw new \RuntimeException('非法 IPv6 CIDR：' . $cidr);
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            throw new \RuntimeException('非法 IPv6 地址：' . $ip);
        }
        return ['bin' => self::maskIpv6($bin, $len), 'len' => $len];
    }

    private static function ipv6CidrOverlap(string $a, string $b): bool
    {
        $pa = self::parseIpv6Cidr($a);
        $pb = self::parseIpv6Cidr($b);
        $len = min((int) $pa['len'], (int) $pb['len']);
        return self::maskIpv6($pa['bin'], $len) === self::maskIpv6($pb['bin'], $len);
    }

    private static function maskIpv6(string $bin, int $len): string
    {
        $bytes = array_values(unpack('C*', $bin));
        for ($i = 0; $i < 16; $i++) {
            $bitsLeft = $len - ($i * 8);
            if ($bitsLeft >= 8) {
                continue;
            }
            if ($bitsLeft <= 0) {
                $bytes[$i] = 0;
            } else {
                $mask = (0xFF << (8 - $bitsLeft)) & 0xFF;
                $bytes[$i] = $bytes[$i] & $mask;
            }
        }
        return pack('C*', ...$bytes);
    }

    /** Add one subnet-sized step to a 16-byte IPv6 network. */
    private static function ipv6AddSubnet(string $bin, int $maskLen): string
    {
        $power = 128 - $maskLen;
        if ($power < 0 || $power >= 128) {
            return $bin;
        }
        $bytes = array_values(unpack('C*', $bin));
        $idx = 15 - intdiv($power, 8);
        $carry = 1 << ($power % 8);
        for ($i = $idx; $i >= 0 && $carry > 0; $i--) {
            $sum = $bytes[$i] + $carry;
            $bytes[$i] = $sum & 0xFF;
            $carry = $sum >> 8;
        }
        for ($i = $idx + 1; $i < 16; $i++) {
            $bytes[$i] = 0;
        }
        return pack('C*', ...$bytes);
    }
}
