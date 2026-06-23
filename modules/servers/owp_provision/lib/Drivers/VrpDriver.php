<?php
/**
 * OWP Provision — Drivers/VrpDriver.php  (v2)
 * ----------------------------------------------------------------------------
 * 华为 VRP 接入交换机驱动：把 v1 的设备侧逻辑（Connection 传输 + Templates 命令块 +
 * 回读校验）收进一个驱动对象，供蓝图/编排器按语义调用。不改命令本身（真机已验证）。
 *
 * 按交付类型（xc/gre，见 Types 注册表）分发开通/拆除模板；限速 = 端口 `qos lr`；
 * 隧道(GRE) 只开通不限速；销户校验无残留（routeHit `net/len` 精确）。
 *
 * 连接配置 + 凭据按 device_id 从 Devices::connConfig 取（白标：真值只在后台）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision\Drivers;

use OwpProvision\Connection;
use OwpProvision\Devices;
use OwpProvision\Templates;
use OwpProvision\Types;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class VrpDriver implements DriverInterface
{
    private int $deviceId;
    private Connection $conn;

    public function __construct(int $deviceId, bool $dryRun)
    {
        $this->deviceId = $deviceId;
        $cfg = Devices::connConfig($deviceId);
        if (empty($cfg)) {
            throw new \RuntimeException('设备 #' . $deviceId . ' 不存在或未配置连接信息。请在后台「设备」页检查。');
        }
        $this->conn = new Connection($cfg, $dryRun);
    }

    public function key(): string
    {
        return 'vrp';
    }

    public function deviceId(): int
    {
        return $this->deviceId;
    }

    public function isDryRun(): bool
    {
        return $this->conn->isDryRun();
    }

    /** 底层连接（少数需要原始 runDisplay/runConfig 的场景用）。 */
    public function conn(): Connection
    {
        return $this->conn;
    }

    public function testConnection(): array
    {
        if ($this->conn->isDryRun()) {
            return ['ok' => true, 'output' => '(dry-run，未触设备)', 'error' => ''];
        }
        return $this->conn->testConnection(true); // 写账号 → display version
    }

    // ----------------------------------------------------------------------
    // 生命周期命令（按交付类型分发；命令块来自 Templates）
    // ----------------------------------------------------------------------

    /** 交付类型 → Templates 方法名（create/teardown）。server 直连子网模型不在 Types 注册表里，单独映射。 */
    private function method(array $alloc, string $which): ?string
    {
        $type = (string) ($alloc['delivery_type'] ?? '');
        if ($type === 'server') {
            return $which === 'teardown' ? 'serverTeardown' : 'serverCreate';
        }
        $d = Types::get($type);
        return $d[$which] ?? null;
    }

    /** 开通：渲染并下发该类型的 create 命令块（含 save+Y）。返回 runConfig 结果。 */
    public function provision(array $alloc, string $custTag): array
    {
        $cm = $this->method($alloc, 'create');
        if (!$cm) {
            throw new \RuntimeException('交付类型「' . ($alloc['delivery_type'] ?? '') . '」无 create 模板。');
        }
        $lines = Templates::$cm($alloc, $custTag);
        return $this->conn->runConfig($lines, (int) ($alloc['serviceid'] ?? 0), 'CreateAccount');
    }

    /** 幂等重下（同 create 命令块）。 */
    public function repush(array $alloc, string $custTag): array
    {
        $cm    = $this->method($alloc, 'create');
        $lines = $cm ? Templates::$cm($alloc, $custTag) : [];
        return $this->conn->runConfig($lines, (int) ($alloc['serviceid'] ?? 0), 'Repush');
    }

    /** 拆除：逆向 undo 命令块。 */
    public function teardown(array $alloc): array
    {
        $tm    = $this->method($alloc, 'teardown');
        $lines = $tm ? Templates::$tm($alloc) : [];
        return $this->conn->runConfig($lines, (int) ($alloc['serviceid'] ?? 0), 'TerminateAccount');
    }

    public function suspend(array $alloc): array
    {
        return $this->conn->runConfig(Templates::suspend($alloc), (int) ($alloc['serviceid'] ?? 0), 'SuspendAccount');
    }

    public function unsuspend(array $alloc): array
    {
        return $this->conn->runConfig(Templates::unsuspend($alloc), (int) ($alloc['serviceid'] ?? 0), 'UnsuspendAccount');
    }

    /** 改带宽：XC 重下端口 qos lr；隧道返回空（不限速）→ runConfig 空命令为 no-op。 */
    public function changeBandwidth(array $alloc): array
    {
        return $this->conn->runConfig(Templates::changeBandwidth($alloc), (int) ($alloc['serviceid'] ?? 0), 'ChangePackage');
    }

    public function greChangeRemote(array $alloc, string $newRemote, string $custTag): array
    {
        return $this->conn->runConfig(Templates::greChangeRemote($alloc, $newRemote, $custTag), (int) ($alloc['serviceid'] ?? 0), 'ChangeRemote');
    }

    /** 原始只读回读（ShowConfig / 隧道状态等）。 */
    public function runDisplay($commands): string
    {
        return $this->conn->runDisplay($commands);
    }

    public function ifaceIsUp(string $out): bool
    {
        return $this->conn->ifaceIsUp($out);
    }

    // ----------------------------------------------------------------------
    // 回读校验（顾问性；不作为开通失败/回滚依据，除拆除残留外）
    // ----------------------------------------------------------------------

    /** 校验交付：接口 UP + 路由命中（net/len 精确）。返回 ['ok','error','detail']。 */
    public function verifyDelivery(array $alloc): array
    {
        $cmds   = Templates::verifyCommands($alloc);
        $detail = [];
        $errors = [];
        try {
            $o = $this->conn->runDisplay($cmds['iface']);
            $detail['iface'] = $o;
            if (!$this->conn->ifaceIsUp($o)) {
                $errors[] = '接口未 UP';
            }
        } catch (\Throwable $e) {
            $errors[] = '读接口失败：' . $e->getMessage();
        }
        if (isset($cmds['route'])) { // server 直连子网无 route-static → 不查 routeHit
            try {
                $pp  = Templates::parsePrefix((string) ($alloc['prefix'] ?? ''));
                $net = (string) $pp['net'];
                $o   = $this->conn->runDisplay($cmds['route']);
                $detail['route'] = $o;
                if (!$this->conn->routeHit($o, $net, $pp['len'])) {
                    $errors[] = '路由未进表（' . $net . '/' . $pp['len'] . '）';
                }
            } catch (\Throwable $e) {
                $errors[] = '读路由失败：' . $e->getMessage();
            }
        }
        return [
            'ok'     => empty($errors),
            'error'  => implode('；', $errors),
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** 校验拆除：接口/路由应不再命中（命中即视为残留）。 */
    public function verifyTeardown(array $alloc): array
    {
        $cmds   = Templates::teardownVerifyCommands($alloc);
        $errors = [];
        try {
            $o = $this->conn->runDisplay($cmds['iface']);
            if ($this->conn->ifaceIsUp($o)) {
                $errors[] = '接口仍存在/UP';
            }
        } catch (\Throwable $e) {
            // 读失败常因接口已删，不计为残留
        }
        if (isset($cmds['route'])) { // server 无 route-static → 不查残留路由
            try {
                $pp = Templates::parsePrefix((string) ($alloc['prefix'] ?? ''));
                $o  = $this->conn->runDisplay($cmds['route']);
                if ($this->conn->routeHit($o, (string) $pp['net'], $pp['len'])) {
                    $errors[] = '路由仍在表（' . $pp['net'] . '/' . $pp['len'] . '）';
                }
            } catch (\Throwable $e) {
            }
        }
        return ['ok' => empty($errors), 'error' => implode('；', $errors)];
    }
}
