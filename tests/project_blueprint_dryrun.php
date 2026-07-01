<?php
/**
 * Dry-run checks for Project / Blueprint resolution.
 *
 * This script intentionally avoids WHMCS DB access and device calls. It loads
 * the module code, verifies legacy product inference, explicit project_key,
 * default blueprint features, and IPv6 Prefixes quantity parsing.
 */

define('WHMCS', true);

require_once __DIR__ . '/../modules/servers/owp_provision/owp_provision.php';

use OwpProvision\Projects;

$failures = [];

function check_true(bool $condition, string $label): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $label;
        echo "[FAIL] {$label}\n";
        return;
    }
    echo "[OK] {$label}\n";
}

function check_same($actual, $expected, string $label): void
{
    check_true($actual === $expected, $label . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

$defaults = [];
foreach (Projects::defaults() as $row) {
    $defaults[$row['project_key']] = $row;
}

foreach (['dedicated_hkbgp', 'dedicated_hkbgp_cn', 'ip_transit_xc', 'ip_transit_gre', 'vpn_l2tp'] as $key) {
    check_true(isset($defaults[$key]), "default project exists: {$key}");
}

check_same(Projects::inferLegacyKey([
    'configoption5' => 'server',
    'configoptions' => ['line' => 'HKBGP'],
]), 'dedicated_hkbgp', 'legacy server HKBGP resolves to dedicated_hkbgp');

check_same(Projects::inferLegacyKey([
    'configoption5' => 'server',
    'configoptions' => ['line' => 'HKBGP-CN'],
]), 'dedicated_hkbgp_cn', 'legacy server HKBGP-CN resolves to dedicated_hkbgp_cn');

check_same(Projects::inferLegacyKey([
    'configoption5' => 'ip_transit',
    'configoptions' => ['delivery_type' => 'xc'],
]), 'ip_transit_xc', 'legacy ip_transit xc resolves to ip_transit_xc');

check_same(Projects::inferLegacyKey([
    'configoption5' => 'ip_transit',
    'configoptions' => ['delivery_type' => 'gre'],
]), 'ip_transit_gre', 'legacy ip_transit gre resolves to ip_transit_gre');

check_same(Projects::inferLegacyKey([
    'configoption5' => 'vpn',
]), 'vpn_l2tp', 'serviceModel vpn resolves to vpn_l2tp');

check_same(Projects::resolveKey([
    'configoption6' => 'dedicated_hkbgp_cn',
    'configoptions' => ['delivery_type' => 'gre'],
]), 'dedicated_hkbgp_cn', 'module configoption6 project_key wins over legacy inference');

check_same(Projects::resolveKey([
    'configoption6' => 'dedicated_hkbgp_cn',
    'configoptions' => ['Project Key' => 'vpn_l2tp'],
]), 'vpn_l2tp', 'configurable Project Key wins over module configoption6');

$dedicatedCn = (object) [
    'project_key' => 'dedicated_hkbgp_cn',
    'features' => json_encode($defaults['dedicated_hkbgp_cn']['features'], JSON_UNESCAPED_SLASHES),
    'bindings' => json_encode($defaults['dedicated_hkbgp_cn']['bindings'], JSON_UNESCAPED_SLASHES),
];

check_true(Projects::hasFeature($dedicatedCn, 'server_binding'), 'dedicated blueprint enables server binding');
check_true(Projects::hasFeature($dedicatedCn, 'ipv4_prefix'), 'dedicated blueprint enables IPv4 prefix');
check_true(Projects::hasFeature($dedicatedCn, 'ipv6_prefix'), 'dedicated blueprint enables IPv6 prefix');
check_true(Projects::hasFeature($dedicatedCn, 'ipmi_vpn'), 'dedicated blueprint enables IPMI VPN');
check_same(Projects::binding($dedicatedCn, 'line_name', ''), 'HKBGP-CN', 'dedicated_hkbgp_cn binds HKBGP-CN line name');
check_same(owpprov_requested_ipv6_count([], $dedicatedCn, true), 1, 'dedicated default IPv6 Prefixes is 1 x /64');
check_same(owpprov_requested_ipv6_count([
    'configoptions' => ['IPv6 Prefixes' => '3 x /64'],
], $dedicatedCn, true), 3, 'configurable IPv6 Prefixes parses multiple /64s');
check_same(owpprov_requested_ipv6_count([
    'customfields' => ['IPv6 Prefixes' => '2'],
], $dedicatedCn, true), 2, 'custom field IPv6 Prefixes fallback parses multiple /64s');

$vpn = (object) [
    'project_key' => 'vpn_l2tp',
    'features' => json_encode($defaults['vpn_l2tp']['features'], JSON_UNESCAPED_SLASHES),
    'bindings' => json_encode($defaults['vpn_l2tp']['bindings'], JSON_UNESCAPED_SLASHES),
];
check_true(Projects::hasFeature($vpn, 'l2tp'), 'vpn_l2tp enables L2TP feature');
check_same(Projects::protocols($vpn), ['l2tp'], 'vpn_l2tp exposes L2TP protocol list');

$activeNames = json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check_true(strpos($activeNames, 'HKBGP-PRO') === false, 'default active project config does not use HKBGP-PRO');

if ($failures) {
    fwrite(STDERR, "\nProject blueprint dry-run failed: " . count($failures) . " failure(s).\n");
    exit(1);
}

echo "\nProject blueprint dry-run checks passed.\n";
