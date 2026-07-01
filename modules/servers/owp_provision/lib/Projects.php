<?php
/**
 * OWP Provision — lib/Projects.php
 * ----------------------------------------------------------------------------
 * Project / Blueprint registry. A WHMCS product can bind `project_key`; when it
 * does not, this class derives a compatible project from the legacy
 * serviceModel + delivery_type + line options.
 *
 * Project rows are configuration only. Secrets stay in device/server config.
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Projects
{
    public const FEATURES = [
        'server_binding'  => 'Dedicated server binding',
        'vlan'            => 'VLAN / Vlanif',
        'ipv4_prefix'     => 'IPv4 prefix',
        'ipv6_prefix'     => 'IPv6 prefix',
        'xc'              => 'XC delivery',
        'gre'             => 'GRE delivery',
        'l2tp'            => 'L2TP VPN',
        'openvpn'         => 'OpenVPN reserved',
        'ikev2'           => 'IKEv2 reserved',
        'ipmi_vpn'        => 'IPMI VPN',
        'bandwidth_limit' => 'Bandwidth / port limit',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function defaults(): array
    {
        return [
            [
                'project_key' => 'dedicated_hkbgp',
                'name' => 'Dedicated HKBGP',
                'service_model' => 'server',
                'default_delivery_type' => 'server',
                'features' => ['server_binding', 'vlan', 'ipv4_prefix', 'ipv6_prefix', 'ipmi_vpn', 'bandwidth_limit'],
                'bindings' => [
                    'line_name' => 'HKBGP',
                    'ipv6_prefix_default' => 1,
                    'protocols' => ['l2tp'],
                ],
                'notes' => 'Default dedicated server blueprint for HKBGP.',
            ],
            [
                'project_key' => 'dedicated_hkbgp_cn',
                'name' => 'Dedicated HKBGP-CN',
                'service_model' => 'server',
                'default_delivery_type' => 'server',
                'features' => ['server_binding', 'vlan', 'ipv4_prefix', 'ipv6_prefix', 'ipmi_vpn', 'bandwidth_limit'],
                'bindings' => [
                    'line_name' => 'HKBGP-CN',
                    'ipv6_prefix_default' => 1,
                    'protocols' => ['l2tp'],
                ],
                'notes' => 'Default dedicated server blueprint for HKBGP-CN.',
            ],
            [
                'project_key' => 'ip_transit_xc',
                'name' => 'IP Transit XC',
                'service_model' => 'ip_transit',
                'default_delivery_type' => 'xc',
                'features' => ['vlan', 'ipv4_prefix', 'xc', 'bandwidth_limit'],
                'bindings' => ['protocols' => []],
                'notes' => 'Legacy-compatible XC transit blueprint.',
            ],
            [
                'project_key' => 'ip_transit_gre',
                'name' => 'IP Transit GRE',
                'service_model' => 'ip_transit',
                'default_delivery_type' => 'gre',
                'features' => ['ipv4_prefix', 'gre', 'bandwidth_limit'],
                'bindings' => ['protocols' => []],
                'notes' => 'Legacy-compatible GRE transit blueprint.',
            ],
            [
                'project_key' => 'vpn_l2tp',
                'name' => 'Standalone L2TP VPN',
                'service_model' => 'vpn',
                'default_delivery_type' => 'vpn',
                'features' => ['l2tp'],
                'bindings' => ['protocols' => ['l2tp']],
                'notes' => 'Standalone VPN blueprint; OpenVPN/IKEv2 remain reserved extension points.',
            ],
        ];
    }

    public static function seedDefaults(): void
    {
        if (!Capsule::schema()->hasTable(Schema::T_PROJECTS)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        foreach (self::defaults() as $row) {
            $key = (string) $row['project_key'];
            if (Capsule::table(Schema::T_PROJECTS)->where('project_key', $key)->exists()) {
                continue;
            }
            Capsule::table(Schema::T_PROJECTS)->insert([
                'project_key' => $key,
                'name' => (string) $row['name'],
                'service_model' => (string) $row['service_model'],
                'default_delivery_type' => (string) $row['default_delivery_type'],
                'features' => self::encodeList($row['features'] ?? []),
                'bindings' => self::encodeMap($row['bindings'] ?? []),
                'enabled' => 1,
                'notes' => (string) ($row['notes'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return object[] */
    public static function all(): array
    {
        try {
            return Capsule::table(Schema::T_PROJECTS)->orderBy('id')->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function get(int $id): ?object
    {
        try {
            return Capsule::table(Schema::T_PROJECTS)->where('id', $id)->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function byKey(string $key): ?object
    {
        $key = self::normalizeKey($key);
        if ($key === '') {
            return null;
        }
        try {
            return Capsule::table(Schema::T_PROJECTS)->where('project_key', $key)->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve project_key from product settings. Configurable option wins over
     * module configoption6; no key falls back to the legacy model.
     */
    public static function resolveKey(array $params): string
    {
        $explicit = self::pluckOption($params, ['project_key', 'Project Key', 'project', 'Project'], '');
        if ($explicit === '') {
            $explicit = (string) ($params['configoption6'] ?? '');
        }
        $explicit = self::normalizeKey($explicit);
        return $explicit !== '' ? $explicit : self::inferLegacyKey($params);
    }

    public static function resolve(array $params): ?object
    {
        return self::byKey(self::resolveKey($params));
    }

    public static function inferLegacyKey(array $params): string
    {
        $serviceModel = strtolower(trim((string) ($params['configoption5'] ?? 'ip_transit')));
        $line = self::pluckOption($params, ['line', 'Line', '线路'], '');
        if ($serviceModel === 'server') {
            return stripos($line, 'CN') !== false ? 'dedicated_hkbgp_cn' : 'dedicated_hkbgp';
        }
        if ($serviceModel === 'vpn') {
            return 'vpn_l2tp';
        }
        $delivery = strtolower(self::pluckOption($params, ['delivery_type', 'Delivery Type'], ''));
        if ($delivery === 'gre') {
            return 'ip_transit_gre';
        }
        return 'ip_transit_xc';
    }

    /** @return string[] */
    public static function features($project): array
    {
        if (!$project) {
            return [];
        }
        return self::decodeList((string) ($project->features ?? '[]'));
    }

    public static function hasFeature($project, string $feature): bool
    {
        return in_array($feature, self::features($project), true);
    }

    /** @return array<string,mixed> */
    public static function bindings($project): array
    {
        if (!$project) {
            return [];
        }
        $raw = json_decode((string) ($project->bindings ?? '{}'), true);
        return is_array($raw) ? $raw : [];
    }

    public static function binding($project, string $key, $default = null)
    {
        $b = self::bindings($project);
        return array_key_exists($key, $b) ? $b[$key] : $default;
    }

    public static function bindingInt($project, string $key, int $default = 0): int
    {
        $v = self::binding($project, $key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return string[] */
    public static function protocols($project): array
    {
        $v = self::binding($project, 'protocols', []);
        if (is_string($v)) {
            $v = array_filter(array_map('trim', explode(',', $v)));
        }
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $p) {
            $p = strtolower(trim((string) $p));
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function save(array $row): int
    {
        $id = (int) ($row['id'] ?? 0);
        $key = self::normalizeKey((string) ($row['project_key'] ?? ''));
        if ($key === '') {
            throw new \RuntimeException('project_key 不能为空。');
        }
        $data = [
            'project_key' => $key,
            'name' => trim((string) ($row['name'] ?? '')),
            'service_model' => trim((string) ($row['service_model'] ?? '')),
            'default_delivery_type' => trim((string) ($row['default_delivery_type'] ?? '')),
            'features' => self::encodeList($row['features'] ?? []),
            'bindings' => self::encodeMap($row['bindings'] ?? []),
            'enabled' => !empty($row['enabled']) ? 1 : 0,
            'notes' => trim((string) ($row['notes'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($data['name'] === '') {
            throw new \RuntimeException('项目名称不能为空。');
        }
        if ($id > 0 && self::get($id)) {
            Capsule::table(Schema::T_PROJECTS)->where('id', $id)->update($data);
            return $id;
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        return (int) Capsule::table(Schema::T_PROJECTS)->insertGetId($data);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        Capsule::table(Schema::T_PROJECTS)->where('id', $id)->update([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = (string) preg_replace('/[^a-z0-9_\\-]/', '_', $key);
        $key = trim($key, '_-');
        return $key;
    }

    public static function pluckOption(array $params, array $names, string $default): string
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

    /** @return string[] */
    private static function decodeList(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $v = (string) $v;
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function encodeList($list): string
    {
        if (is_string($list)) {
            $list = array_filter(array_map('trim', explode(',', $list)));
        }
        if (!is_array($list)) {
            $list = [];
        }
        $out = [];
        foreach ($list as $v) {
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function encodeMap($map): string
    {
        if (is_string($map)) {
            $decoded = json_decode($map, true);
            $map = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($map)) {
            $map = [];
        }
        return json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
