<?php
/**
 * OWP Provision — Drivers/DriverInterface.php  (v2)
 * ----------------------------------------------------------------------------
 * 设备驱动统一接口。一类设备一个驱动（vrp=华为 VRP 交换机 / ros=MikroTik RouterOS /
 * drac=服务器 BMC iDRAC）。设备表 `driver` 字段决定用哪个；蓝图按语义调用驱动方法，
 * 新增设备类型只加一个 Driver、不动蓝图。
 *
 * 此接口只规定**所有驱动共有**的能力（标识 + 连接自检）；各驱动再加自己的语义方法
 * （如 VrpDriver::provision / RosDriver::vpnGrant / DracDriver::createUser）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision\Drivers;

if (!defined('WHMCS')) {
    die('Access Denied');
}

interface DriverInterface
{
    /** 驱动标识，与设备表 `driver` 值对应：vrp|ros|drac。 */
    public function key(): string;

    /** 本驱动操作的设备 id。 */
    public function deviceId(): int;

    /** 是否 dry-run（只渲染不触设备）。 */
    public function isDryRun(): bool;

    /**
     * 连接自检。返回 ['ok'=>bool, 'output'=>string, 'error'=>string]。
     * dry-run 视为通过（不触设备）。
     */
    public function testConnection(): array;
}
