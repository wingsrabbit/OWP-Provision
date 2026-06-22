<?php
/**
 * Bundled phpseclib v3 (+ paragonie/constant_time_encoding) 加载器 — IP-Delivery 自带。
 *
 * 目的：让运行期 class_exists('phpseclib3\Net\SSH2') == true → Connection 的 v3 分支自动启用，
 *      RSA 私钥走 **rsa-sha2**（SHA-2）签名 → 通过现代 OpenSSH（≥8.8 默认禁 ssh-rsa/SHA-1）
 *      跳板的公钥认证。（WHMCS 自带的是 phpseclib v2，对 RSA 只发 ssh-rsa，会被现代跳板拒。）
 *
 * 为什么连 constant_time 一起带：本类部署的 WHMCS 自带 phpseclib 是老 v2 且**不含**
 *      paragonie/constant_time_encoding（其 vendor/ 里没有）→ v3 依赖它，故一并 bundle。
 *      若将来某环境 WHMCS 已提供 ParagonIE\ConstantTime\*，下面的 fallback 注册会自动让路。
 *
 * 冲突规避（fallback 注册，应对本轮最大风险）：本 autoloader 只在「该类尚未可加载」时
 *      才用 bundle 文件，**绝不覆盖** WHMCS / 其它模块已定义的同名类（避免 Cannot redeclare）。
 *      命名空间 `phpseclib3\` 与 WHMCS 的 v2 `phpseclib\` 不撞，可共存。
 *
 * 可回退：删除本 sshv3/ 目录即自动回退 v2（Connection::ssh2Class()/loadKey() 有 v2 兜底）。
 *
 * 第三方库版本：phpseclib 3.0.53、paragonie/constant_time_encoding v2.8.2（均 MIT，见同目录 *-LICENSE.txt）。
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

(static function (): void {
    $base = __DIR__;

    // phpseclib3 的 files-autoload bootstrap（仅一处 mbstring 检查，幂等无副作用）。
    $bootstrap = $base . '/phpseclib3/bootstrap.php';
    if (is_file($bootstrap)) {
        require_once $bootstrap;
    }

    // PSR-4 前缀 → bundle 目录
    $map = [
        'phpseclib3\\'              => $base . '/phpseclib3/',
        'ParagonIE\\ConstantTime\\' => $base . '/constant_time/',
    ];

    spl_autoload_register(static function ($class) use ($map): void {
        foreach ($map as $prefix => $dir) {
            $len = strlen($prefix);
            if (strncmp($class, $prefix, $len) !== 0) {
                continue;
            }
            // fallback：若该类已可加载（如 WHMCS/其它模块已提供），让路、不接管。
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                return;
            }
            $file = $dir . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return; // 命中前缀即停（无论文件是否存在），把控制权交回 PHP 的下一个 autoloader
        }
    });
})();
