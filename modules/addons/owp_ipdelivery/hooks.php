<?php
/**
 * IP-Delivery addon hooks 桥接。
 * ----------------------------------------------------------------------------
 * WHMCS 会全局加载**已激活 addon 模块**的 `hooks.php`，但**不会**自动加载
 * server/provisioning 模块目录下的 `hooks.php`。下单交互的 hooks
 * （ClientAreaFooterOutput 注入 JS + ClientAreaPage 的 freeports AJAX）
 * 写在 server 模块的 hooks.php 里，故在此桥接 require，让它们得以注册。
 *
 * server hooks.php 内的 `__DIR__` 仍指向 server 模块目录（PHP 魔术常量按定义
 * 所在文件解析，不随 require 调用位置改变），其 `__DIR__.'/lib/...'` 相对路径不受影响。
 */
if (!defined('WHMCS')) {
    die('Access Denied');
}

$ipdServerHooks = __DIR__ . '/../../servers/owp_ipdelivery/hooks.php';
if (is_file($ipdServerHooks)) {
    require_once $ipdServerHooks;
}
