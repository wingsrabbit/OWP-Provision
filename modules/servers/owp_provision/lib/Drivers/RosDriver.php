<?php
/**
 * OWP Provision — Drivers/RosDriver.php  (v2)
 * ----------------------------------------------------------------------------
 * MikroTik RouterOS 驱动：给客户开 VPN 接入（到其 IPMI）+ 给 WHMCS 开临时 DNAT 通道（管 iDRAC）。
 *
 * 与 VRP 传输根本不同：RouterOS = **私钥(或密码) SSH 登录 + 每条命令 exec 即返回、无交互提示符、
 * 无 save**（RouterOS 改动即时持久）。故本驱动自带轻量 exec 传输（复用打包的 phpseclib v3），
 * 不用 VRP 的 shellRun。
 *
 * VPN 模型（白标，站点值全部来自设备配置 ros_* + deviceSecret('ros_ipsec_psk')）：
 *   一条 `/ppp secret`（service=any）即同时支持 **L2TP / PPTP / SSTP / OpenVPN**；另建每客户
 *   IKEv2 identity（若设备配了 ros_ikev2_peer）。每客户专属 profile（pin 一个固定 VPN /32）+ 隔离
 *   filter：**只通 ① 公网(NAT 出 wan) ② 自己的 IPMI；其余 forward/input 全 drop**。所有对象打统一
 *   注释 `owp-svc{id}`，grant=先 revoke 再建（幂等）、revoke=按注释删净。
 *
 * iDRAC 通道：dnatOpen 在 ROS 开一条「只放行 WHMCS 源 IP」的 dst-nat（公网端口→IPMI:目标端口），
 *   WHMCS 经它走 Redfish 配 iDRAC；用完 dnatClose 撤掉（临时通道，平时关）。
 *
 * 真机命令由运维在 ROS 上验收/微调（命令串集中在本类）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3 · RouterOS 7.x
 */

namespace OwpProvision\Drivers;

use OwpProvision\Devices;
use OwpProvision\Config;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class RosDriver implements DriverInterface
{
    /** RouterOS 回显里的报错标记（命中即视为该命令失败）。 */
    private const ERR_MARKERS = [
        'syntax error', 'expected ', 'failure:', 'no such item', 'bad command name',
        'input does not match', 'ambiguous', 'invalid value', 'cannot ',
    ];

    private int $deviceId;
    private bool $dryRun;
    private ?object $dev;        // devices 行（含 ros_* 站点字段）
    private array $cfg;          // connConfig（传输：host/port/user/key/pass）
    private $ssh = null;         // 已登录的 phpseclib SSH2（懒连）
    private array $dryLog = [];  // dry-run 收集的命令

    public function __construct(int $deviceId, bool $dryRun)
    {
        $this->deviceId = $deviceId;
        $this->dryRun   = $dryRun;
        $this->dev      = Devices::get($deviceId);
        $this->cfg      = Devices::connConfig($deviceId);
        if ($this->dev === null || empty($this->cfg)) {
            throw new \RuntimeException('ROS 设备 #' . $deviceId . ' 不存在或未配置连接信息。');
        }
    }

    public function key(): string
    {
        return 'ros';
    }

    public function deviceId(): int
    {
        return $this->deviceId;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /** dry-run 时收集的命令（便于落日志/预览）。 */
    public function dryRunCommands(): array
    {
        return $this->dryLog;
    }

    // ----------------------------------------------------------------------
    // 传输：懒连（key 优先，回退密码）+ 单命令 exec + 报错识别
    // ----------------------------------------------------------------------

    private function connect(): void
    {
        if ($this->ssh !== null) {
            return;
        }
        if (!class_exists('phpseclib3\\Net\\SSH2')) {
            $autoload = __DIR__ . '/../sshv3/autoload.php';
            if (is_file($autoload)) {
                @require_once $autoload;
            }
        }
        if (!class_exists('phpseclib3\\Net\\SSH2')) {
            throw new \RuntimeException('未找到 phpseclib v3（ROS 驱动需要）。');
        }
        $host    = (string) ($this->cfg['deviceHost'] ?? '');
        $port    = (int) ($this->cfg['devicePort'] ?? 22);
        $user    = trim((string) ($this->cfg['writeUser'] ?? '')) ?: 'admin';
        $timeout = (int) ($this->cfg['timeout'] ?? 30);
        if ($host === '') {
            throw new \RuntimeException('ROS 设备 IP（deviceHost）未配置。');
        }
        $sshCls = 'phpseclib3\\Net\\SSH2';
        $ssh    = new $sshCls($host, $port, $timeout > 0 ? $timeout : 15);
        if (method_exists($ssh, 'setTimeout')) {
            $ssh->setTimeout($timeout > 0 ? $timeout : 30);
        }
        $cred = $this->credential();
        if (!$ssh->login($user, $cred)) {
            if (method_exists($ssh, 'isConnected') && !$ssh->isConnected()) {
                throw new \RuntimeException('无法连接 ROS ' . $host . ':' . $port . '（网络/防火墙/超时）。');
            }
            throw new \RuntimeException('ROS 认证失败（user=' . $user . '；私钥/密码不被接受）。');
        }
        $this->ssh = $ssh;
    }

    /** 私钥（jumpKeyText/jumpKeyPath，复用同一把 key）优先；否则密码 writePass。 */
    private function credential()
    {
        $keyText = trim((string) ($this->cfg['jumpKeyText'] ?? ''));
        $keyPath = (string) ($this->cfg['jumpKeyPath'] ?? '');
        $keyPass = (string) ($this->cfg['jumpKeyPassphrase'] ?? '');
        $keyData = '';
        if ($keyText !== '') {
            $keyData = $keyText;
        } elseif ($keyPath !== '' && @is_readable($keyPath)) {
            $keyData = (string) @file_get_contents($keyPath);
        }
        if ($keyData !== '') {
            $keyData = str_replace(["\r\n", "\r"], "\n", $keyData);
            $loader  = 'phpseclib3\\Crypt\\PublicKeyLoader';
            return $loader::load($keyData, $keyPass !== '' ? $keyPass : false);
        }
        $pass = (string) ($this->cfg['writePass'] ?? '');
        if ($pass === '') {
            throw new \RuntimeException('ROS 凭据未配置：请填私钥（jumpKeyText/jumpKeyPath）或密码（writePass）。');
        }
        return $pass;
    }

    /** 执行一条 RouterOS 命令；dry-run 只收集。报错抛异常。返回回显（去 CRLF）。 */
    public function exec(string $command): string
    {
        if ($this->dryRun) {
            $this->dryLog[] = $command;
            return '(dry-run)';
        }
        $this->connect();
        $out = (string) $this->ssh->exec($command . "\n");
        $clean = trim(str_replace("\r", '', $out));
        $low = strtolower($clean);
        foreach (self::ERR_MARKERS as $m) {
            if (strpos($low, $m) !== false) {
                throw new \RuntimeException('ROS 命令失败：' . $command . ' → ' . mb_substr($clean, 0, 300));
            }
        }
        return $clean;
    }

    /** 批量执行（任一失败即抛，dry-run 全收集）。返回各命令回显数组。 */
    private function execAll(array $commands): array
    {
        $out = [];
        foreach ($commands as $c) {
            if (trim($c) === '') {
                continue;
            }
            $out[] = $this->exec($c);
        }
        return $out;
    }

    public function testConnection(): array
    {
        if ($this->dryRun) {
            return ['ok' => true, 'output' => '(dry-run，未触设备)', 'error' => ''];
        }
        try {
            $out = $this->exec(':put [/system resource get version]');
            return ['ok' => $out !== '', 'output' => $out, 'error' => $out === '' ? '无回显' : ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
        }
    }

    // ----------------------------------------------------------------------
    // 站点字段 / 工具
    // ----------------------------------------------------------------------

    public static function tag(int $serviceId): string
    {
        return 'owp-svc' . $serviceId;
    }

    private function lanIf(): string
    {
        return trim((string) ($this->dev->ros_lan_if ?? ''));
    }

    private function wanIf(): string
    {
        return trim((string) ($this->dev->ros_wan_if ?? ''));
    }

    private function l2tpLocal(): string
    {
        return trim((string) ($this->dev->ros_l2tp_local ?? ''));
    }

    private function ikev2Peer(): string
    {
        return trim((string) ($this->dev->ros_ikev2_peer ?? ''));
    }

    /** RouterOS 字符串值安全包裹（转义 " 和 \）。 */
    private static function q(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    // ----------------------------------------------------------------------
    // VPN 授权 / 回收（幂等：grant 先 revoke 再建；revoke 按注释删净）
    // ----------------------------------------------------------------------

    /**
     * 给一个服务开 VPN：固定地址 $custVpnIp，只能到自己的 $ipmiTarget + 公网。
     * 账号 = $username/$password（一条 ppp secret service=any 覆盖 L2TP/PPTP/SSTP/OpenVPN；
     * 另建 IKEv2 identity 若设备配了 ros_ikev2_peer）。
     *
     * @return array 执行的命令数（dry-run 时含命令清单）
     */
    public function vpnGrant(int $serviceId, string $custVpnIp, string $ipmiTarget, string $username, string $password): array
    {
        $lan = $this->lanIf();
        $wan = $this->wanIf();
        $loc = $this->l2tpLocal();
        if ($lan === '' || $wan === '' || $loc === '') {
            throw new \RuntimeException('ROS 站点字段未配置（请在设备页填 ros_lan_if / ros_wan_if / ros_l2tp_local）。');
        }
        if ($custVpnIp === '' || $ipmiTarget === '' || $username === '') {
            throw new \RuntimeException('VPN 授权缺参数（vpnIp/ipmiTarget/username）。');
        }
        $tag = self::tag($serviceId);

        // 1) 幂等：先删旧
        $this->vpnRevoke($serviceId);

        // 2) profile（pin 固定地址）+ secret（service=any → L2TP/PPTP/SSTP/OpenVPN 共用）
        $cmds = [
            '/ppp profile add name=' . self::q($tag) . ' local-address=' . $loc
                . ' remote-address=' . $custVpnIp . ' only-one=yes change-tcp-mss=yes',
            '/ppp secret add name=' . self::q($username) . ' password=' . self::q($password)
                . ' service=any profile=' . self::q($tag) . ' comment=' . self::q($tag),
            // 3) 隔离 filter（顺序：先放行，后兜底 drop）
            '/ip firewall filter add chain=forward action=accept src-address=' . $custVpnIp
                . ' dst-address=' . $ipmiTarget . ' out-interface=' . $lan . ' comment=' . self::q($tag),
            '/ip firewall filter add chain=forward action=accept src-address=' . $custVpnIp
                . ' out-interface=' . $wan . ' comment=' . self::q($tag),
            '/ip firewall filter add chain=forward action=drop src-address=' . $custVpnIp . ' comment=' . self::q($tag),
            '/ip firewall filter add chain=input action=drop src-address=' . $custVpnIp . ' comment=' . self::q($tag),
            // 4) 公网出 NAT
            '/ip firewall nat add chain=srcnat action=masquerade src-address=' . $custVpnIp
                . ' out-interface=' . $wan . ' comment=' . self::q($tag),
        ];

        // 5) IKEv2（可选；需设备预置全局 peer）。per-user mode-config pin 地址 + identity（EAP）。
        $peer = $this->ikev2Peer();
        if ($peer !== '') {
            $cmds[] = '/ip ipsec mode-config add name=' . self::q($tag) . ' address=' . $custVpnIp
                . ' address-prefix-length=32 comment=' . self::q($tag);
            $cmds[] = '/ip ipsec identity add peer=' . self::q($peer) . ' auth-method=eap-radius'
                . ' mode-config=' . self::q($tag) . ' generate-policy=port-strict comment=' . self::q($tag);
        }

        $this->execAll($cmds);
        return ['commands' => count($cmds), 'dryRun' => $this->dryRun, 'list' => $this->dryRun ? $this->dryLog : []];
    }

    /** 回收一个服务的全部 VPN 对象（按注释 owp-svc{id} 删净；不存在则无操作）。 */
    public function vpnRevoke(int $serviceId): array
    {
        $tag = self::q(self::tag($serviceId));
        $cmds = [
            '/ppp active remove [find name~' . self::q(self::tag($serviceId)) . ']', // 踢掉在线会话（按需）
            '/ppp secret remove [find comment=' . $tag . ']',
            '/ppp profile remove [find comment=' . $tag . ']',
            '/ip firewall filter remove [find comment=' . $tag . ']',
            '/ip firewall nat remove [find comment=' . $tag . ']',
            '/ip ipsec identity remove [find comment=' . $tag . ']',
            '/ip ipsec mode-config remove [find comment=' . $tag . ']',
        ];
        // revoke 容错：逐条执行，单条失败（如对象不存在）不阻断其余。
        $done = 0;
        foreach ($cmds as $c) {
            try {
                $this->exec($c);
                $done++;
            } catch (\Throwable $e) {
                // ignore（remove 不存在对象等）
            }
        }
        return ['commands' => $done, 'dryRun' => $this->dryRun];
    }

    // ----------------------------------------------------------------------
    // iDRAC 临时管理通道（dst-nat，只放行 WHMCS 源 IP；用完即撤）
    // ----------------------------------------------------------------------

    /** 开临时 DNAT：pubPort → ipmiTarget:targetPort，仅源 $srcAllow 可达。注释 owp-svc{id}:dnat。 */
    public function dnatOpen(int $serviceId, string $ipmiTarget, int $targetPort, string $srcAllow, int $pubPort): array
    {
        $wan = $this->wanIf();
        if ($wan === '') {
            throw new \RuntimeException('ROS ros_wan_if 未配置，无法开管理 DNAT。');
        }
        $tag = self::q(self::tag($serviceId) . ':dnat');
        $this->exec('/ip firewall nat remove [find comment=' . $tag . ']'); // 幂等
        $cmd = '/ip firewall nat add chain=dstnat action=dst-nat'
            . ' protocol=tcp in-interface=' . $wan . ' dst-port=' . $pubPort
            . ' src-address=' . $srcAllow
            . ' to-addresses=' . $ipmiTarget . ' to-ports=' . $targetPort
            . ' comment=' . $tag;
        $this->exec($cmd);
        return ['ok' => true, 'pubPort' => $pubPort, 'dryRun' => $this->dryRun];
    }

    /** 撤掉该服务的临时管理 DNAT。 */
    public function dnatClose(int $serviceId): void
    {
        $tag = self::q(self::tag($serviceId) . ':dnat');
        try {
            $this->exec('/ip firewall nat remove [find comment=' . $tag . ']');
        } catch (\Throwable $e) {
        }
    }
}
