<?php
/**
 * OWP Provision — lib/Lines.php  (v2.8 · 线路实体, P8)
 * ----------------------------------------------------------------------------
 * 线路是**独立维度**：决定交付哪段公网 IP（线路 ↔ 交付池组，见 Pools.line_id）+ 落地接入交换机。
 * 不再是服务器属性（servers 去掉 line）。客户下单选线路 → 用该线路池组发 IP、挑该交换机的空闲机。
 * VPN/IPMI 的 vpn_ip 是带外管理通道、与线路无关。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Lines
{
    public static function all(): array
    {
        return Capsule::table(Schema::T_LINES)->orderBy('id')->get()->all();
    }

    public static function enabled(): array
    {
        return Capsule::table(Schema::T_LINES)->where('enabled', 1)->orderBy('id')->get()->all();
    }

    public static function get(int $id): ?object
    {
        return Capsule::table(Schema::T_LINES)->where('id', $id)->first() ?: null;
    }

    public static function byName(string $name): ?object
    {
        return Capsule::table(Schema::T_LINES)->where('name', $name)->first() ?: null;
    }

    public static function add(string $name, string $descr, ?int $deviceId): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_LINES)->insertGetId([
            'name' => $name, 'descr' => $descr !== '' ? $descr : null,
            'device_id' => $deviceId ?: null, 'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function update(int $id, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(Schema::T_LINES)->where('id', $id)->update($fields);
    }

    public static function delete(int $id): void
    {
        Capsule::table(Schema::T_LINES)->where('id', $id)->delete();
    }
}
