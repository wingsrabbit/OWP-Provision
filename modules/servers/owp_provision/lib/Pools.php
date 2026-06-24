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
        return (int) Capsule::table(Schema::T_POOL_GROUPS)->insertGetId([
            'name' => $name, 'purpose' => $purpose === 'vpn' ? 'vpn' : 'delivery',
            'line_id' => $lineId ?: null, 'device_id' => $deviceId ?: null,
            'deliver_min' => $min, 'deliver_max' => $max, 'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
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
        $p   = Ipam::parseCidr($cidr); // 校验 + 规范网络地址
        $net = long2ip(ip2long($p['net']) & ((0xFFFFFFFF << (32 - $p['len'])) & 0xFFFFFFFF));
        $now = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_POOL_BLOCKS)->insertGetId([
            'group_id' => $groupId, 'cidr' => $net . '/' . $p['len'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function deleteBlock(int $id): void
    {
        Capsule::table(Schema::T_POOL_BLOCKS)->where('id', $id)->delete();
    }

    // ------------------------------------------------------------ 选组 + 分配
    /**
     * 找交付池组：优先按 line_id（P8 线路驱动），否则按落地 device_id。返回首个 enabled 组或 null。
     */
    public static function findDeliveryGroup(?int $deviceId, ?int $lineId = null): ?object
    {
        $q = Capsule::table(Schema::T_POOL_GROUPS)->where('purpose', 'delivery')->where('enabled', 1);
        if ($lineId) {
            $q->where('line_id', $lineId);
        } elseif ($deviceId) {
            $q->where('device_id', $deviceId);
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
        $taken = array_merge(self::activePrefixes(), $exclude); // 全系统在用 + 额外排除
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
}
