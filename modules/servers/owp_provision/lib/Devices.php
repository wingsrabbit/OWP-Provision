<?php
/**
 * IP-Delivery — Devices.php  
 * ----------------------------------------------------------------------------
 * 每台接入交换机一条记录（`mod_owp_provision_devices`，非敏感连接配置）。敏感凭据按设备
 * 加密存 `mod_owp_provision_config`（见 Config::deviceSecret*，key=dev{id}_*）。
 *
 *   - 列表 / 增 / 改 / 删 / 启停（CRUD）。
 *   - connConfig($id)：把设备行 + 解密凭据组装成 Connection 期望的配置数组（替代旧全局）。
 *   - defaultId()：唯一启用设备 → 其 id（下单免选）；多启用 → 0（须显式选节点）。
 *   - hasActiveAllocations($id)：删除前校验（有在用分配则拒删）。
 *
 * 不连真机；纯 DB（Capsule）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Devices
{
    /** devices 表可写的非敏感列（白名单，防止越权写入）。 */
    private const COLS = [
        'name', 'driver', 'enabled', 'conn_mode', 'device_host', 'device_port',
        'write_user', 'read_user', 'kex',
        'jump_host', 'jump_port', 'jump_user', 'jump_key_path', 'timeout',
        'ros_lan_if', 'ros_wan_if', 'ros_l2tp_local', 'ros_ikev2_peer',
    ];

    /** @return array<int,object> 全部设备（按 id） */
    public static function all(): array
    {
        try {
            return Capsule::table(Schema::T_DEVICES)->orderBy('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,object> 启用设备 */
    public static function enabled(): array
    {
        try {
            return Capsule::table(Schema::T_DEVICES)->where('enabled', 1)->orderBy('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function get($id): ?object
    {
        try {
            $r = Capsule::table(Schema::T_DEVICES)->where('id', (int) $id)->first();
            return $r ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function exists($id): bool
    {
        return self::get($id) !== null;
    }

    public static function isEnabled($id): bool
    {
        $d = self::get($id);
        return $d !== null && (int) $d->enabled === 1;
    }

    public static function count(): int
    {
        try {
            return (int) Capsule::table(Schema::T_DEVICES)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 唯一启用设备 → id（下单免选/后端默认）；0 启用或多启用 → 0（须显式选节点）。 */
    public static function defaultId(): int
    {
        $en = self::enabled();
        return count($en) === 1 ? (int) $en[0]->id : 0;
    }

    /** 该设备是否有在用（非 terminated）分配。删除前校验。 */
    public static function hasActiveAllocations(int $id): bool
    {
        try {
            return Capsule::table(Schema::T_ALLOCATIONS)
                ->where('device_id', $id)->where('status', '!=', 'terminated')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 新建设备，返回新 id。 */
    public static function create(array $row): int
    {
        $data               = self::sanitize($row);
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_DEVICES)->insertGetId($data);
    }

    public static function update(int $id, array $row): void
    {
        $data               = self::sanitize($row);
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(Schema::T_DEVICES)->where('id', $id)->update($data);
    }

    public static function setEnabled(int $id, bool $on): void
    {
        Capsule::table(Schema::T_DEVICES)->where('id', $id)
            ->update(['enabled' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** 删除设备 + 清该设备所有加密凭据。调用方须先用 hasActiveAllocations 校验。 */
    public static function delete(int $id): void
    {
        Capsule::table(Schema::T_DEVICES)->where('id', $id)->delete();
        foreach (Config::SECRET_KEYS as $k) {
            Config::deleteDeviceSecret($id, $k);
        }
    }

    /** 只保留白名单列；enabled 归一为 0/1。 */
    private static function sanitize(array $row): array
    {
        $out = [];
        foreach (self::COLS as $c) {
            if (array_key_exists($c, $row)) {
                $out[$c] = $row[$c];
            }
        }
        if (array_key_exists('enabled', $out)) {
            $out['enabled'] = in_array(strtolower((string) $out['enabled']), ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
        }
        return $out;
    }

    /**
     * 组装某设备的连接配置（供 Connection / 各 Driver）：设备行映射到 Connection 期望键
     * + 解密的 dev{id}_ 凭据。设备不存在 → 空数组（调用方报错）。
     */
    public static function connConfig(int $id): array
    {
        $d = self::get($id);
        if ($d === null) {
            return [];
        }
        $cfg = [
            'connMode'    => (string) ($d->conn_mode ?? 'jump'),
            'deviceHost'  => (string) ($d->device_host ?? ''),
            'devicePort'  => (string) ($d->device_port ?? '22'),
            'writeUser'   => (string) ($d->write_user ?? ''),
            'readUser'    => (string) ($d->read_user ?? ''),
            'kex'         => (string) ($d->kex ?? ''),
            'jumpHost'    => (string) ($d->jump_host ?? ''),
            'jumpPort'    => (string) ($d->jump_port ?? '22'),
            'jumpUser'    => (string) ($d->jump_user ?? 'root'),
            'jumpKeyPath' => (string) ($d->jump_key_path ?? ''),
            'timeout'     => (string) ($d->timeout ?? '30'),
        ];
        foreach (Config::SECRET_KEYS as $k) {
            $cfg[$k] = Config::deviceSecret($id, $k);
        }
        return $cfg;
    }
}
