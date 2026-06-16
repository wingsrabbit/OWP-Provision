<?php
/**
 * IP-Delivery — Config.php
 * ----------------------------------------------------------------------------
 * 两类存储：
 *   1) 全局非敏感设置（enabledTypes / frontendTypes / globalDryRun 等）：addon `_config`
 *      → `tbladdonmodules`（明文，按明文读）。
 *   2) **每设备**敏感凭据（写/读密码、跳板密码、私钥口令、私钥内容）：`EncryptPassword`
 *      加密存 `mod_ipdelivery_config`，key 带设备前缀 `dev{id}_{name}`（每设备独立凭据）。
 *
 * 连接配置从「addon 全局单设备」改为「`mod_ipdelivery_devices` 多设备」（见 Devices）；
 * 本类只保留全局设置读取 + 每设备凭据加解密。旧的全局 writePass 等已由 Schema 迁移成 dev1_。
 *
 * 全部走 Capsule + localAPI，不硬编码任何凭据。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace IpDelivery;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Config
{
    public const ADDON_MODULE = 'owp_ipdelivery';

    /** 每设备敏感凭据键（加密存 mod_ipdelivery_config 的 dev{id}_{key}）。 */
    public const SECRET_KEYS = ['writePass', 'readPass', 'jumpPass', 'jumpKeyPassphrase', 'jumpKeyText'];

    /** @var array<string,string>|null 全局设置进程内缓存 */
    private static ?array $cache = null;

    // ----------------------------------------------------------------------
    // 全局非敏感设置（tbladdonmodules，明文）
    // ----------------------------------------------------------------------

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        try {
            foreach (Capsule::table('tbladdonmodules')->where('module', self::ADDON_MODULE)->get() as $r) {
                $out[(string) $r->setting] = (string) ($r->value ?? '');
            }
        } catch (\Throwable $e) {
            // addon 未 Activate / 表异常：返回空，调用方按默认处理。
        }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key, string $default = ''): string
    {
        $a = self::all();
        return array_key_exists($key, $a) && $a[$key] !== '' ? $a[$key] : $default;
    }

    public static function globalDryRun(): bool
    {
        return in_array(strtolower(self::get('globalDryRun', '')), ['1', 'on', 'yes', 'true'], true);
    }

    /** 综合 dry-run：addon 全局 或 该产品 ConfigOptions dryRun 任一开 → true。 */
    public static function isDryRun(array $params): bool
    {
        if (self::globalDryRun()) {
            return true;
        }
        foreach ([$params['dryRun'] ?? null, $params['configoption4'] ?? null] as $c) {
            if ($c !== null && in_array(strtolower((string) $c), ['1', 'on', 'yes', 'true'], true)) {
                return true;
            }
        }
        return false;
    }

    // ----------------------------------------------------------------------
    // 每设备敏感凭据（mod_ipdelivery_config，key = dev{id}_{name}，加密）
    // ----------------------------------------------------------------------

    private static function devKey(int $deviceId, string $key): string
    {
        return 'dev' . $deviceId . '_' . $key;
    }

    public static function deviceSecret(int $deviceId, string $key): string
    {
        try {
            $enc = (string) Capsule::table(Schema::T_CONFIG)
                ->where('setting', self::devKey($deviceId, $key))->value('value');
        } catch (\Throwable $e) {
            return '';
        }
        return $enc !== '' ? self::decrypt($enc) : '';
    }

    /** 保存某设备的一个敏感项（加密）。空值 = 删除该项。 */
    public static function setDeviceSecret(int $deviceId, string $key, string $plain): void
    {
        $setting = self::devKey($deviceId, $key);
        if ($plain === '') {
            Capsule::table(Schema::T_CONFIG)->where('setting', $setting)->delete();
            return;
        }
        $enc = self::encrypt($plain);
        if (Capsule::table(Schema::T_CONFIG)->where('setting', $setting)->exists()) {
            Capsule::table(Schema::T_CONFIG)->where('setting', $setting)
                ->update(['value' => $enc, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            Capsule::table(Schema::T_CONFIG)->insert([
                'setting' => $setting, 'value' => $enc, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function deleteDeviceSecret(int $deviceId, string $key): void
    {
        try {
            Capsule::table(Schema::T_CONFIG)->where('setting', self::devKey($deviceId, $key))->delete();
        } catch (\Throwable $e) {
        }
    }

    /** 某设备各敏感项是否已设置（不泄露内容）。 */
    public static function deviceSecretStatus(int $deviceId): array
    {
        $st = [];
        foreach (self::SECRET_KEYS as $k) {
            $st[$k] = self::deviceSecret($deviceId, $k) !== '';
        }
        return $st;
    }

    // ----------------------------------------------------------------------
    // 加解密（localAPI Encrypt/DecryptPassword，用本 WHMCS 的 CC_ENCRYPTION_HASH）
    // ----------------------------------------------------------------------

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (function_exists('localAPI')) {
            try {
                $res = localAPI('EncryptPassword', ['password2' => $plain]);
                if (isset($res['password']) && $res['password'] !== '') {
                    return (string) $res['password'];
                }
            } catch (\Throwable $e) {
            }
        }
        return $plain;
    }

    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        if (function_exists('localAPI')) {
            try {
                $res = localAPI('DecryptPassword', ['password2' => $encrypted]);
                if (isset($res['password'])) {
                    return (string) $res['password'];
                }
            } catch (\Throwable $e) {
            }
        }
        return $encrypted;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
