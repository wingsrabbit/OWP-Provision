<?php
/**
 * IP-Delivery — Types.php：交付类型注册表（可配置 / 可扩展）
 * ----------------------------------------------------------------------------
 * 把交付类型从散落的 `=== 'xc'/'gre'` 硬编码抽象成注册结构。每个类型含：
 *   key / label / create（Templates 生成方法名）/ teardown（拆除方法名）/
 *   defEnabled（默认启用）/ defFrontend（默认开放前端下单）。
 *
 * 启用与前端开放可被 addon 配置覆盖（`enabledTypes` / `frontendTypes` 逗号列表）；
 * 缺省回落到各类型的 defEnabled/defFrontend。
 *
 * 新增交付类型（将来换设备/新协议）只需：
 *   1) 这里 defs() 加一项（key/label/create/teardown/默认开关）；
 *   2) 配套 Ipam::allocateX（其入参可与 xc/gre 不同）+ Templates::xCreate/xTeardown；
 *   3) 如该类型渲染/校验有别，补 Templates::verifyCommands/staticRouteLine 分支。
 * 核心分发（server 模块的开通/拆除）走本注册表，无需再动。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace IpDelivery;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Types
{
    /**
     * 内置交付类型定义。
     * 当前：XC = 启用 + 前端开放；GRE = 启用（代码可用）+ 前端关闭（pending）。
     * 设备实测仅支持 GRE 隧道（ipv4-ipv4/ipsec/mpls 不支持），故不内置 IPIP。
     */
    private static function defs(): array
    {
        return [
            'xc' => [
                'key'        => 'xc',
                'label'      => 'XC (Cross-Connect)',
                'create'     => 'xcCreate',
                'teardown'   => 'xcTeardown',
                'defEnabled' => true,
                'defFrontend' => true,
            ],
            'gre' => [
                'key'        => 'gre',
                'label'      => 'GRE Tunnel',
                'create'     => 'greCreate',
                'teardown'   => 'greTeardown',
                'defEnabled' => true,
                'defFrontend' => false, // 代码可用、admin 可手动开通；默认不开放前端下单
            ],
        ];
    }

    /** 解析类型 key 列表（分隔符 , / + / 空白；小写、去空、仅保留已定义的、去重）。 */
    private static function parseList(string $csv): array
    {
        $defs = self::defs();
        $out  = [];
        foreach (preg_split('/[,+\s]+/', strtolower(trim($csv))) as $k) {
            $k = trim($k);
            if ($k !== '' && isset($defs[$k])) {
                $out[$k] = true;
            }
        }
        return array_keys($out);
    }

    /** 启用的类型 key 列表（addon 配置 enabledTypes 覆盖；缺省=各类型 defEnabled）。 */
    public static function enabledKeys(): array
    {
        $cfg = trim(Config::get('enabledTypes', ''));
        if ($cfg !== '') {
            return self::parseList($cfg);
        }
        $out = [];
        foreach (self::defs() as $k => $d) {
            if (!empty($d['defEnabled'])) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** 开放前端下单的类型 key（frontendTypes 覆盖；缺省=各类型 defFrontend；与 enabled 取交集）。 */
    public static function frontendKeys(): array
    {
        $cfg = trim(Config::get('frontendTypes', ''));
        if ($cfg !== '') {
            $fe = self::parseList($cfg);
        } else {
            $fe = [];
            foreach (self::defs() as $k => $d) {
                if (!empty($d['defFrontend'])) {
                    $fe[] = $k;
                }
            }
        }
        return array_values(array_intersect($fe, self::enabledKeys()));
    }

    public static function isEnabled(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::enabledKeys(), true);
    }

    public static function isFrontend(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::frontendKeys(), true);
    }

    /** 取类型定义（含 create/teardown 方法名、label）；未知返回 null。 */
    public static function get(string $key): ?array
    {
        return self::defs()[strtolower(trim($key))] ?? null;
    }

    public static function label(string $key): string
    {
        $d = self::get($key);
        return $d['label'] ?? strtoupper($key);
    }
}
