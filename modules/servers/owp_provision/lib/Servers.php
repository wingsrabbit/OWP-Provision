<?php
/**
 * OWP Provision — Servers.php  (v2 服务器库存)
 * ----------------------------------------------------------------------------
 * 租赁/托管的物理机资产清单（`mod_owp_provision_servers`），admin 维护。下单时按线路挑一台空闲
 * 服务器并**原子绑定**（事务 + 行锁，防并发双租）；它带着「所在交换机 device_id + 端口 + IPMI +
 * 所在 ROS」——开通蓝图据此定交换机端口、开 IPMI VPN。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Servers
{
    private const COLS = [
        'name', 'device_id', 'port', 'vpn_device_id', 'ipmi_ip', 'ipmi_kind', 'line', 'specs', 'status',
    ];

    public static function all(): array
    {
        try {
            return Capsule::table(Schema::T_SERVERS)->orderBy('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function get($id): ?object
    {
        try {
            $r = Capsule::table(Schema::T_SERVERS)->where('id', (int) $id)->first();
            return $r ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function byService(int $serviceId): ?object
    {
        try {
            $r = Capsule::table(Schema::T_SERVERS)->where('serviceid', $serviceId)->first();
            return $r ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 某线路下的空闲服务器（line 空=不限线路；服务器 line 空=通配任意线路）。 */
    public static function freeForLine(string $line = ''): array
    {
        try {
            $q = Capsule::table(Schema::T_SERVERS)->where('status', 'free')->whereNull('serviceid');
            if ($line !== '') {
                $q->where(function ($w) use ($line) {
                    $w->where('line', $line)->orWhereNull('line')->orWhere('line', '');
                });
            }
            return $q->orderBy('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function create(array $row): int
    {
        $data               = self::sanitize($row);
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_SERVERS)->insertGetId($data);
    }

    public static function update(int $id, array $row): void
    {
        $data               = self::sanitize($row);
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(Schema::T_SERVERS)->where('id', $id)->update($data);
    }

    public static function setStatus(int $id, string $status): void
    {
        Capsule::table(Schema::T_SERVERS)->where('id', $id)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function delete(int $id): void
    {
        Capsule::table(Schema::T_SERVERS)->where('id', $id)->delete();
    }

    public static function isRented(int $id): bool
    {
        $s = self::get($id);
        return $s !== null && (string) $s->status === 'rented';
    }

    /**
     * 为服务原子绑定一台服务器（事务 + 行锁）。幂等：该服务已绑则直接返回。
     * $wantId>0 时绑指定（须空闲）；否则按 $line 挑首个空闲。
     * @throws \RuntimeException 无可用服务器 / 指定的不可用
     */
    public static function bindFree(int $serviceId, string $line = '', int $wantId = 0): object
    {
        return Capsule::connection()->transaction(function () use ($serviceId, $line, $wantId) {
            // 幂等：已绑
            $exist = Capsule::table(Schema::T_SERVERS)->where('serviceid', $serviceId)->lockForUpdate()->first();
            if ($exist) {
                return $exist;
            }
            if ($wantId > 0) {
                $s = Capsule::table(Schema::T_SERVERS)->where('id', $wantId)->lockForUpdate()->first();
                if (!$s) {
                    throw new \RuntimeException('指定服务器 #' . $wantId . ' 不存在。');
                }
                if ((string) $s->status !== 'free' || $s->serviceid !== null) {
                    throw new \RuntimeException('指定服务器 #' . $wantId . ' 不空闲。');
                }
            } else {
                $q = Capsule::table(Schema::T_SERVERS)->where('status', 'free')->whereNull('serviceid');
                if ($line !== '') {
                    $q->where(function ($w) use ($line) {
                        $w->where('line', $line)->orWhereNull('line')->orWhere('line', '');
                    });
                }
                $s = $q->orderBy('id')->lockForUpdate()->first();
                if (!$s) {
                    throw new \RuntimeException('无空闲服务器' . ($line !== '' ? '（线路 ' . $line . '）' : '') . '可分配。');
                }
            }
            Capsule::table(Schema::T_SERVERS)->where('id', (int) $s->id)->update([
                'status' => 'rented', 'serviceid' => $serviceId, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $s->status    = 'rented';
            $s->serviceid = $serviceId;
            return $s;
        });
    }

    /** 释放某服务占用的服务器（回到 free）。 */
    public static function releaseByService(int $serviceId): void
    {
        Capsule::table(Schema::T_SERVERS)->where('serviceid', $serviceId)->update([
            'status' => 'free', 'serviceid' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function sanitize(array $row): array
    {
        $out = [];
        foreach (self::COLS as $c) {
            if (array_key_exists($c, $row)) {
                $out[$c] = $row[$c];
            }
        }
        if (array_key_exists('device_id', $out)) {
            $out['device_id'] = (int) $out['device_id'];
        }
        if (array_key_exists('vpn_device_id', $out)) {
            $out['vpn_device_id'] = $out['vpn_device_id'] !== '' && $out['vpn_device_id'] !== null ? (int) $out['vpn_device_id'] : null;
        }
        return $out;
    }
}
