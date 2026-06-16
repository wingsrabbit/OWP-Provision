<?php
/**
 * IP-Delivery — Resources.php  
 * ----------------------------------------------------------------------------
 * 每条具体资源一行（`mod_ipdelivery_resources`）。占用**不落库**，由 `allocations` 实时算
 * （删掉旧的 meta.exclude 手工排除）。本类负责：
 *   - 列表 / 增 / 改 / 删 / 启停（CRUD；占用中保护）。
 *   - 占用判定 occupant()：标量精确匹配；ptp/prefix 用 CIDR 重叠。
 *   - 空闲条目 freeItems()：供 Ipam 分配「从清单挑一条空闲」。
 *   - 校验 validate()：格式 + 查重 + 重叠（不查上游，只管接入）。
 *   - 母段切分 expand()：按掩码切成具体条目（不入库，调用方校验后批量 add）。
 *
 * 复用 Ipam 的纯 PHP CIDR 工具（运行期 Ipam 已 require；二者无 load 期循环）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace IpDelivery;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Resources
{
    public const KINDS      = ['vlan', 'ptp', 'tunnel', 'prefix', 'port', 'loopback', 'acl'];
    public const INT_KINDS  = ['vlan', 'tunnel', 'acl'];   // 整数标量
    public const CIDR_KINDS = ['ptp', 'prefix', 'loopback']; // 带掩码的 CIDR/IP
    private const MAX_SPLIT  = 1024;                          // 单次母段切分条目上限（防失控）

    // ----------------------------------------------------------------------
    // 读取
    // ----------------------------------------------------------------------

    public static function get(int $id): ?object
    {
        try {
            $r = Capsule::table(Schema::T_RESOURCES)->where('id', $id)->first();
            return $r ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 某设备某类资源（已排序：整数按数值、CIDR 按 IP、端口按字典序）。 */
    public static function listByDevice(int $deviceId, string $kind): array
    {
        try {
            $rows = Capsule::table(Schema::T_RESOURCES)
                ->where('device_id', $deviceId)->where('kind', $kind)->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
        usort($rows, static function ($a, $b) use ($kind) {
            if (in_array($kind, self::INT_KINDS, true)) {
                return (int) $a->value <=> (int) $b->value;
            }
            if (in_array($kind, self::CIDR_KINDS, true)) {
                $la = (int) (ip2long(explode('/', (string) $a->value)[0]) & 0xFFFFFFFF);
                $lb = (int) (ip2long(explode('/', (string) $b->value)[0]) & 0xFFFFFFFF);
                if ($la !== $lb) {
                    return $la <=> $lb;
                }
                return (int) ($a->mask ?? 0) <=> (int) ($b->mask ?? 0);
            }
            return strcmp((string) $a->value, (string) $b->value);
        });
        return $rows;
    }

    /** 某设备某类资源条数。 */
    public static function countByDevice(int $deviceId, string $kind): int
    {
        try {
            return (int) Capsule::table(Schema::T_RESOURCES)
                ->where('device_id', $deviceId)->where('kind', $kind)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ----------------------------------------------------------------------
    // 占用（实时从 allocations 算）
    // ----------------------------------------------------------------------

    /** 某设备的在用（非 terminated）分配（一次取出，供 occupant 批量复用）。 */
    public static function activeAllocations(int $deviceId): array
    {
        try {
            return Capsule::table(Schema::T_ALLOCATIONS)
                ->where('device_id', $deviceId)->where('status', '!=', 'terminated')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 该资源被哪个分配占用 → 返回分配行（含 serviceid）或 null。
     * 标量（vlan/tunnel/acl/port/loopback）精确匹配；ptp/prefix 用 CIDR 重叠。
     * @param array $allocs 预取的 active 分配（Resources::activeAllocations）
     */
    public static function occupant(object $res, array $allocs): ?object
    {
        $kind = (string) $res->kind;
        $val  = (string) $res->value;
        $mask = $res->mask !== null ? (int) $res->mask : null;
        foreach ($allocs as $a) {
            switch ($kind) {
                case 'vlan':
                    if ($a->vlan_id !== null && (int) $a->vlan_id === (int) $val) {
                        return $a;
                    }
                    break;
                case 'tunnel':
                    if ($a->tunnel_id !== null && (int) $a->tunnel_id === (int) $val) {
                        return $a;
                    }
                    break;
                case 'acl':
                    if ($a->acl_id !== null && (int) $a->acl_id === (int) $val) {
                        return $a;
                    }
                    break;
                case 'port':
                    if (!empty($a->port) && Ipam::normalizePort((string) $a->port) === Ipam::normalizePort($val)) {
                        return $a;
                    }
                    break;
                case 'loopback':
                    if (!empty($a->loopback_ip) && (string) $a->loopback_ip === $val) {
                        return $a;
                    }
                    break;
                case 'ptp':
                    if (!empty($a->ptp_net) && self::overlapSafe($val . '/' . ($mask ?? 30), (string) $a->ptp_net)) {
                        return $a;
                    }
                    break;
                case 'prefix':
                    if (!empty($a->prefix) && self::overlapSafe($val . '/' . ($mask ?? 32), (string) $a->prefix)) {
                        return $a;
                    }
                    break;
            }
        }
        return null;
    }

    public static function isOccupied(int $id): bool
    {
        $r = self::get($id);
        if (!$r) {
            return false;
        }
        return self::occupant($r, self::activeAllocations((int) $r->device_id)) !== null;
    }

    /** 空闲（enabled 且未占用）条目，供 Ipam 分配挑选。 */
    public static function freeItems(int $deviceId, string $kind): array
    {
        $allocs = self::activeAllocations($deviceId);
        $free   = [];
        foreach (self::listByDevice($deviceId, $kind) as $r) {
            if ((int) $r->enabled !== 1) {
                continue;
            }
            if (self::occupant($r, $allocs) !== null) {
                continue;
            }
            $free[] = $r;
        }
        return $free;
    }

    private static function overlapSafe(string $a, string $b): bool
    {
        try {
            return Ipam::cidrOverlap($a, $b);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ----------------------------------------------------------------------
    // 写（CRUD）
    // ----------------------------------------------------------------------

    public static function add(int $deviceId, string $kind, string $value, ?int $mask, string $source = 'manual', ?string $note = null): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_RESOURCES)->insertGetId([
            'device_id' => $deviceId, 'kind' => $kind, 'value' => $value, 'mask' => $mask,
            'source' => $source, 'enabled' => 1, 'note' => $note,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** 批量入库（母段切分用）。$items = [[value,mask], ...]。 */
    public static function addMany(int $deviceId, string $kind, array $items, string $source = 'auto', ?string $note = null): int
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                'device_id' => $deviceId, 'kind' => $kind, 'value' => (string) $it[0],
                'mask' => $it[1] !== null ? (int) $it[1] : null,
                'source' => $source, 'enabled' => 1, 'note' => $note,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if (empty($rows)) {
            return 0;
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Capsule::table(Schema::T_RESOURCES)->insert($chunk);
        }
        return count($rows);
    }

    /** 编辑单条（value/mask/enabled）。占用保护由调用方先 isOccupied 校验。 */
    public static function update(int $id, string $value, ?int $mask, int $enabled): void
    {
        Capsule::table(Schema::T_RESOURCES)->where('id', $id)->update([
            'value' => $value, 'mask' => $mask, 'enabled' => $enabled ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function setEnabled(int $id, bool $on): void
    {
        Capsule::table(Schema::T_RESOURCES)->where('id', $id)
            ->update(['enabled' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function delete(int $id): void
    {
        Capsule::table(Schema::T_RESOURCES)->where('id', $id)->delete();
    }

    /** 删除某设备全部资源（删除设备时调用）。 */
    public static function deleteByDevice(int $deviceId): void
    {
        Capsule::table(Schema::T_RESOURCES)->where('device_id', $deviceId)->delete();
    }

    // ----------------------------------------------------------------------
    // 校验（格式 + 查重 + 重叠；不查上游，只管接入合理性）
    // ----------------------------------------------------------------------

    /**
     * 校验一条资源。返回错误串数组（空=通过）。$excludeId：编辑时排除自身。
     */
    public static function validate(int $deviceId, string $kind, string $value, ?int $mask, int $excludeId = 0): array
    {
        $errs = [];
        if (!in_array($kind, self::KINDS, true)) {
            return ['非法资源类型：' . $kind];
        }
        // 1) 格式
        if (in_array($kind, self::INT_KINDS, true)) {
            if (!ctype_digit($value)) {
                $errs[] = $kind . ' 必须是整数：' . $value;
            } elseif ((int) $value <= 0) {
                $errs[] = $kind . ' 必须为正整数';
            }
        } elseif ($kind === 'port') {
            if (trim($value) === '') {
                $errs[] = '端口名为空';
            } elseif (strpos($value, ',') !== false) {
                $errs[] = '单条只填一个端口；多个请用「母段切分」（逗号分隔）';
            }
        } else { // CIDR 类
            if ($mask === null || $mask < 0 || $mask > 32) {
                $errs[] = '掩码非法（应 0-32）';
            }
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $errs[] = 'IP/网络地址非法：' . $value;
            }
            if (empty($errs)) {
                $net = self::networkOf($value, (int) $mask);
                if ($net !== $value) {
                    $errs[] = $value . '/' . $mask . ' 不是网络地址（应为 ' . $net . '/' . $mask . '）';
                }
            }
        }
        if (!empty($errs)) {
            return $errs;
        }
        // 2) 查重（同 device+kind+value+mask）
        $dup = Capsule::table(Schema::T_RESOURCES)
            ->where('device_id', $deviceId)->where('kind', $kind)->where('value', $value);
        if ($mask === null) {
            $dup->whereNull('mask');
        } else {
            $dup->where('mask', $mask);
        }
        if ($excludeId > 0) {
            $dup->where('id', '!=', $excludeId);
        }
        if ($dup->exists()) {
            $errs[] = '已存在相同资源：' . $value . ($mask !== null ? '/' . $mask : '');
        }
        // 3) 重叠（仅 CIDR 类，与同 device+kind 其它条目相交）
        if (empty($errs) && in_array($kind, self::CIDR_KINDS, true)) {
            $others = Capsule::table(Schema::T_RESOURCES)
                ->where('device_id', $deviceId)->where('kind', $kind);
            if ($excludeId > 0) {
                $others->where('id', '!=', $excludeId);
            }
            foreach ($others->get() as $o) {
                try {
                    if (Ipam::cidrOverlap($value . '/' . $mask, $o->value . '/' . ($o->mask ?? 32))) {
                        $errs[] = '与现有条目重叠：' . $o->value . '/' . ($o->mask ?? '');
                        break;
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        return $errs;
    }

    // ----------------------------------------------------------------------
    // 母段切分（不入库；返回 [value,mask] 列表 + 错误）
    // ----------------------------------------------------------------------

    /**
     * @return array{items: array<int,array{0:string,1:?int}>, errors: array<int,string>}
     */
    public static function expand(string $kind, string $master, ?int $splitMask): array
    {
        $items  = [];
        $errors = [];
        if (!in_array($kind, self::KINDS, true)) {
            return ['items' => [], 'errors' => ['非法资源类型：' . $kind]];
        }
        if (in_array($kind, self::INT_KINDS, true)) {
            $ints = Ipam::expandVlanRange($master);
            if (empty($ints)) {
                $errors[] = '范围解析为空（示例 120-130 或 120,130-135）';
            }
            if (count($ints) > self::MAX_SPLIT) {
                return ['items' => [], 'errors' => ['范围展开 ' . count($ints) . ' 条过多（>' . self::MAX_SPLIT . '），请缩小']];
            }
            foreach ($ints as $n) {
                $items[] = [(string) $n, null];
            }
        } elseif ($kind === 'port') {
            foreach (array_map('trim', explode(',', $master)) as $p) {
                if ($p !== '') {
                    $items[] = [$p, null];
                }
            }
            if (empty($items)) {
                $errors[] = '端口列表为空（逗号分隔）';
            }
        } else { // CIDR 类：按 splitMask 切
            if ($splitMask === null || $splitMask < 0 || $splitMask > 32) {
                return ['items' => [], 'errors' => ['请选择合法切分掩码（0-32）']];
            }
            try {
                $pl = (int) Ipam::parseCidr($master)['len'];
                if ($splitMask < $pl) {
                    return ['items' => [], 'errors' => ['切分掩码 /' . $splitMask . ' 比母段 /' . $pl . ' 还大（无意义）']];
                }
                $cnt = 1 << ($splitMask - $pl);
                if ($cnt > self::MAX_SPLIT) {
                    return ['items' => [], 'errors' => ['将切出 ' . $cnt . ' 条过多（>' . self::MAX_SPLIT . '），请缩小母段或增大掩码']];
                }
                foreach (Ipam::splitCidr($master, $splitMask) as $cidr) {
                    $items[] = [explode('/', $cidr)[0], $splitMask];
                }
            } catch (\Throwable $e) {
                $errors[] = '母段非法：' . $e->getMessage();
            }
        }
        return ['items' => $items, 'errors' => $errors];
    }

    /** 对一批 [value,mask] 逐条校验，返回错误（任一条不过即收集）。供母段切分保存前用。 */
    public static function validateMany(int $deviceId, string $kind, array $items): array
    {
        $errs = [];
        foreach ($items as $it) {
            $e = self::validate($deviceId, $kind, (string) $it[0], $it[1] !== null ? (int) $it[1] : null);
            foreach ($e as $msg) {
                $errs[] = $it[0] . ($it[1] !== null ? '/' . $it[1] : '') . '：' . $msg;
            }
        }
        return $errs;
    }

    private static function networkOf(string $ip, int $mask): string
    {
        $l = ip2long($ip);
        if ($l === false) {
            return $ip;
        }
        $m = $mask === 0 ? 0 : ((0xFFFFFFFF << (32 - $mask)) & 0xFFFFFFFF);
        return long2ip($l & $m);
    }
}
