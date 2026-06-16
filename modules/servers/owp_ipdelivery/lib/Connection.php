<?php
/**
 * IP-Delivery — Connection.php
 * ----------------------------------------------------------------------------
 * SSH 连接层，支持**两种连接方式**（由配置 connMode 选择）：
 *
 *   A) direct（直连）：WHMCS 用 phpseclib **直接 SSH 到交换机**（IP+端口+用户+密码）。
 *      —— 设备能从 WHMCS 所在主机直达时用（最简单）。
 *      ⚠ 接入交换机 SSH 白名单只放特定网段时，从公网 WHMCS 主机直连会被设备挡，
 *        此设备需用 jump 模式；direct 留给「设备可直达」的部署。
 *
 *   B) jump（跳板）：WHMCS 用 phpseclib 连一台跳板机（私钥或密码），在跳板上用
 *      `sshpass ssh`（带老 KEX）再登设备执行。—— 设备只放跳板网段时用。
 *
 * 两种方式最终都是「在设备上 exec 一个命令块」，差别只在传输：
 *   direct: phpseclib($device)->exec($block)
 *   jump:   phpseclib($jump)->exec("sshpass -p … ssh … $device '$block'")
 *
 * 用 **WHMCS 自带的 phpseclib**（vendor/，v3 优先、v2 回退）；**不引入新依赖、不硬编码
 * 任何凭据**。所有连接参数从 $config（来自 addon 配置）取。
 *
 * 命令块：配置类自动追加 save + 应答 Y，回读校验 save 成功；识别 VRP 报错；去 CRLF；
 * 设备执行完主动断连视为正常。读账号 vs 写账号：display/核查用读账号；下发用写账号。
 *
 * @package IpDelivery
 * @target  WHMCS 9.0.4 / PHP 8.3
 */

namespace IpDelivery;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Connection
{
    /** @var array 连接配置（见 configKeys()） */
    private array $cfg;

    /** @var bool 全局 dry-run（addon 全局 或 ConfigOptions 任一开则 true） */
    private bool $dryRun;

    /** VRP 报错特征（命中即判失败）。 */
    private const VRP_ERROR_MARKERS = [
        'Error:',
        'Wrong',
        'Incomplete command',
        'Unrecognized command',
        'Too many parameters',
        'Ambiguous command',
        '% ',                 // 通用 VRP 错误前缀（仅当行以 % 开头才算，见 findVrpError）
    ];

    /** 跳板/设备执行完主动断连的「正常」特征（不当错误）。 */
    private const NORMAL_DISCONNECT_MARKERS = [
        'Connection reset by peer',
        'Connection reset',
        'Broken pipe',
        'Connection to ',
        'closed by remote host',
    ];

    public function __construct(array $config, bool $dryRun = false)
    {
        $this->cfg    = $config;
        $this->dryRun = $dryRun;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /** 连接模式：direct | jump（默认 jump，适配设备 SSH 白名单；direct 留给可直达部署）。 */
    private function connMode(): string
    {
        $m = strtolower(trim((string) ($this->cfg['connMode'] ?? 'jump')));
        return $m === 'direct' ? 'direct' : 'jump';
    }

    /**
     * 把命令行数组拼成「设备一次执行的命令块」。首行强制 screen-length 0 temporary。
     *
     * @param string[] $lines
     * @param bool     $appendSaveConfirm 配置类下发用 true（追加 save + Y）
     */
    public function buildBlock(array $lines, bool $appendSaveConfirm): string
    {
        $all = array_merge(['screen-length 0 temporary'], $lines);
        if ($appendSaveConfirm) {
            // save 后设备问 "Are you sure ...? [Y/N]"，下一行送 Y。
            $all[] = 'save';
            $all[] = 'Y';
        }
        return implode("\n", $all) . "\n";
    }

    // ----------------------------------------------------------------------
    // 公共：配置下发 / 只读 display / 测试
    // ----------------------------------------------------------------------

    /**
     * 下发配置命令块（自动 save+Y），并校验 save 成功。
     *
     * @param string[] $lines     命令行数组（system-view…return；不含 save/Y）
     * @param int      $serviceId 仅用于日志关联
     * @param string   $action    仅用于日志（如 'CreateAccount'）
     * @return array{ok:bool, dryrun:bool, block:string, output:string, error:string}
     */
    public function runConfig(array $lines, int $serviceId = 0, string $action = ''): array
    {
        $block = $this->buildBlock($lines, true);

        if ($this->dryRun) {
            $this->auditLog($serviceId, $action, $block, '(dry-run：未下发)', 'dryrun');
            return ['ok' => true, 'dryrun' => true, 'block' => $block, 'output' => '', 'error' => ''];
        }

        try {
            $raw = $this->execOnDevice($block, true); // 写账号
        } catch (\Throwable $e) {
            $this->auditLog($serviceId, $action, $block, $e->getMessage(), 'error');
            return ['ok' => false, 'dryrun' => false, 'block' => $block, 'output' => '', 'error' => $e->getMessage()];
        }

        $clean = $this->stripCrlf($raw);

        $vrpErr = $this->findVrpError($clean);
        if ($vrpErr !== '') {
            $this->auditLog($serviceId, $action, $block, $clean, 'error');
            return ['ok' => false, 'dryrun' => false, 'block' => $block, 'output' => $clean,
                    'error' => 'VRP 报错：' . $vrpErr];
        }

        if (!$this->saveConfirmed($clean)) {
            $this->auditLog($serviceId, $action, $block, $clean, 'error');
            return ['ok' => false, 'dryrun' => false, 'block' => $block, 'output' => $clean,
                    'error' => 'save 未确认成功（回显未见保存成功提示）。配置可能已生效但未落盘，请人工核查。'];
        }

        $this->auditLog($serviceId, $action, $block, $clean, 'success');
        return ['ok' => true, 'dryrun' => false, 'block' => $block, 'output' => $clean, 'error' => ''];
    }

    /**
     * 下发只读 display 命令（读账号，不进 system-view、不 save）。
     *
     * @param string|string[] $commands
     * @param bool             $useWrite 强制用写账号（少数预检场景）；默认读账号
     * @return string 清洗后的回显
     * @throws \RuntimeException
     */
    public function runDisplay($commands, bool $useWrite = false): string
    {
        $cmds  = is_array($commands) ? $commands : [$commands];
        $lines = array_merge(['screen-length 0 temporary'], $cmds);
        $block = implode("\n", $lines) . "\n";

        // dry-run 只拦「配置下发」，不拦只读 display（读不改设备）。若希望完全离线，
        // 让上层在 dry-run 时不调用 runDisplay 即可。
        $raw = $this->execOnDevice($block, $useWrite);
        return $this->stripCrlf($raw);
    }

    /**
     * TestConnection：连通 + 可登 + display version。
     * @param bool $useWrite 用写账号测（验证自动化账号可用）
     * @return array{ok:bool, output:string, error:string}
     */
    public function testConnection(bool $useWrite = false): array
    {
        try {
            $out = $this->runDisplay([Templates::displayVersion()], $useWrite);
            $ok  = (stripos($out, 'VRP') !== false) || (stripos($out, 'Version') !== false);
            return ['ok' => $ok, 'output' => $out,
                    'error' => $ok ? '' : '已连接但 display version 回显异常（未见 VRP/Version）。'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
        }
    }

    // ----------------------------------------------------------------------
    // 底层：在设备上 exec 命令块（按 connMode 分派）
    // ----------------------------------------------------------------------

    /**
     * 在设备上执行命令块。返回设备原始回显（含 CRLF）。
     * @throws \RuntimeException
     */
    private function execOnDevice(string $deviceBlock, bool $useWrite): string
    {
        return $this->connMode() === 'direct'
            ? $this->execDirect($deviceBlock, $useWrite)
            : $this->execViaJump($deviceBlock, $useWrite);
    }

    /**
     * 方式 A：phpseclib 直连设备 → **交互式 shell** 逐行下发。
     *
     * 🔴 实测（phpseclib v2，目标交换机 CLI）：exec 通道**只认单条命令、不吃多行命令块**（含
     *    screen-length/system-view），多行 exec → 空回显。故 direct 一律走交互 shell：
     *    write 一行 → read 到提示符；配置下发的 save / [Y/N]→Y 也在交互里完成。
     *    （只读 display 也走交互，统一逻辑。）仅改传输方式，命令内容/Templates/Ipam 不动。
     *
     * @throws \RuntimeException
     */
    private function execDirect(string $deviceBlock, bool $useWrite): string
    {
        [$devUser, $devPass] = $useWrite ? $this->writeCreds() : $this->readCreds();
        if ($devUser === '' || $devPass === '') {
            throw new \RuntimeException(($useWrite ? '写' : '读') . '账号用户名/密码未配置（direct 模式必填）。');
        }
        $devHost = (string) ($this->cfg['deviceHost'] ?? '');
        $devPort = (int) ($this->cfg['devicePort'] ?? 22);
        if ($devHost === '') {
            throw new \RuntimeException('设备 IP（deviceHost）未配置。');
        }

        $ssh = $this->newSsh($devHost, $devPort);
        if (!$this->tryLogin($ssh, $devUser, $devPass)) {
            // 区分「连不上」与「认证拒」：phpseclib v2 login() 在连接失败时也返回 false。
            if (method_exists($ssh, 'isConnected') && !$ssh->isConnected()) {
                throw new \RuntimeException('无法连接设备 ' . $devHost . ':' . $devPort
                    . '（网络/防火墙/超时，或 KEX/算法不被接受）。请确认本机到设备已放行。');
            }
            throw new \RuntimeException('设备认证失败（用户名/密码不被接受）。user=' . $devUser);
        }

        $out = $this->shellRun($ssh, $deviceBlock);
        if (trim($out) === '') {
            throw new \RuntimeException('设备无回显（交互 shell 未读到内容）。请检查账号权限与设备响应/提示符。');
        }
        return $this->postCheck($out);
    }

    /**
     * 交互式 shell 逐行执行：write 一行 → read 到 VRP 提示符（行尾 ] 或 >，含 [Y/N]）。
     * 读到 [Y/N] 时，下一行（命令块里的 Y）会被送出应答。复用 findVrpError/saveConfirmed 判定回显。
     *
     * @param object $ssh   已登录的 SSH2
     * @param string $block 多行命令块（首行通常 screen-length 0 temporary）
     */
    private function shellRun($ssh, string $block): string
    {
        $timeout = (int) ($this->cfg['timeout'] ?? 30);
        if (method_exists($ssh, 'setTimeout')) {
            $ssh->setTimeout($timeout > 0 ? $timeout : 30);
        }
        $cls    = get_class($ssh);
        $REGEX  = defined($cls . '::READ_REGEX') ? constant($cls . '::READ_REGEX') : 2; // v2/v3 同名
        // 提示符 expect：行尾 ] 或 >（用户/系统/接口视图），或确认提示 [Y/N]。
        $prompt = '#(\[Y/N\][^\n]*|[>\]])\s*$#i';

        $out = '';
        // 先消费登录后的初始提示符，避免逐行读位移错位。
        try {
            $out .= (string) $ssh->read($prompt, $REGEX);
        } catch (\Throwable $e) {
            // 初始无提示符（少见）：忽略，靠下方逐行读。
        }

        foreach (explode("\n", rtrim($block, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $ssh->write($line . "\n");
            try {
                $out .= (string) $ssh->read($prompt, $REGEX);
            } catch (\Throwable $e) {
                $out .= "\n[read error: " . $e->getMessage() . "]\n";
                break;
            }
        }
        if (method_exists($ssh, 'disconnect')) {
            try {
                $ssh->disconnect();
            } catch (\Throwable $e) {
            }
        }
        return $out;
    }

    /**
     * 方式 B：phpseclib 连跳板（私钥优先，回退密码）→ 跳板上 sshpass ssh 登设备 exec。
     * @throws \RuntimeException
     */
    private function execViaJump(string $deviceBlock, bool $useWrite): string
    {
        $ssh = $this->connectJump();

        [$devUser, $devPass] = $useWrite ? $this->writeCreds() : $this->readCreds();
        if ($devUser === '' || $devPass === '') {
            throw new \RuntimeException(($useWrite ? '写' : '读') . '账号用户名/密码未配置。');
        }
        $devHost = (string) ($this->cfg['deviceHost'] ?? '');
        $devPort = (int) ($this->cfg['devicePort'] ?? 8007);
        $kex     = trim((string) ($this->cfg['kex'] ?? 'diffie-hellman-group14-sha256,diffie-hellman-group-exchange-sha256'));
        if ($devHost === '') {
            throw new \RuntimeException('设备 IP（deviceHost）未配置。');
        }

        $remoteCmd = $this->buildJumpExec($devUser, $devPass, $devHost, $devPort, $kex, $deviceBlock);
        $out = $ssh->exec($remoteCmd);
        $out = ($out === false || $out === null) ? '' : (string) $out;

        if (trim($out) === '') {
            $probe = $ssh->exec('command -v sshpass || echo __NO_SSHPASS__');
            if (strpos((string) $probe, '__NO_SSHPASS__') !== false) {
                throw new \RuntimeException('跳板上未安装 sshpass（command -v sshpass 为空）。请在跳板安装 sshpass。');
            }
            throw new \RuntimeException('设备无任何回显（命令块可能未执行/认证失败/KEX 不匹配）。请检查写账号、设备端口与 KEX。');
        }
        return $this->postCheck($out);
    }

    /** 回显里识别认证失败 / KEX 失败，给可读报错；否则原样返回。 */
    private function postCheck(string $out): string
    {
        $low = strtolower($out);
        if (strpos($low, 'permission denied') !== false || strpos($low, 'authentication failed') !== false
            || strpos($low, 'access denied') !== false) {
            throw new \RuntimeException('设备认证失败（账号/密码错误或权限不足）。回显：' . $this->snippet($out));
        }
        if (strpos($low, 'no matching key exchange') !== false
            || (strpos($low, 'kex') !== false && strpos($low, 'no match') !== false)) {
            throw new \RuntimeException('SSH KEX 协商失败（设备只给老算法，请确认 KexAlgorithms/算法支持）。回显：' . $this->snippet($out));
        }
        return $out;
    }

    /**
     * 稳妥构造跳板上执行的 sshpass ssh 命令。KexAlgorithms 带 '+'（追加）。
     * 密码、用户@主机、命令块都用 escapeshellarg 转义（在 WHMCS 主机(posix) 构造，跳板 sh 解释）。
     */
    private function buildJumpExec(string $devUser, string $devPass, string $devHost, int $devPort, string $kex, string $deviceBlock): string
    {
        $kexOpt = 'KexAlgorithms=+' . $kex;
        $parts = [
            'sshpass', '-p', escapeshellarg($devPass),
            'ssh',
            '-p', (string) $devPort,
            '-o', escapeshellarg('StrictHostKeyChecking=no'),
            '-o', escapeshellarg('UserKnownHostsFile=/dev/null'),
            '-o', escapeshellarg('ConnectTimeout=20'),
            '-o', escapeshellarg($kexOpt),
            escapeshellarg($devUser . '@' . $devHost),
            escapeshellarg($deviceBlock),
        ];
        return implode(' ', $parts);
    }

    /**
     * 连跳板（phpseclib）。优先私钥（jumpKeyPath），无私钥则用 jumpPass 密码登录。
     * @return object phpseclib SSH2（已登录）
     * @throws \RuntimeException
     */
    private function connectJump()
    {
        $jumpHost = (string) ($this->cfg['jumpHost'] ?? '');
        $jumpPort = (int) ($this->cfg['jumpPort'] ?? 22);
        $jumpUser = (string) ($this->cfg['jumpUser'] ?? 'root');
        $keyText  = trim((string) ($this->cfg['jumpKeyText'] ?? ''));   // 内存私钥（加密存库，不落盘）
        $keyPath  = (string) ($this->cfg['jumpKeyPath'] ?? '');
        $keyPass  = (string) ($this->cfg['jumpKeyPassphrase'] ?? '');
        $jumpPass = (string) ($this->cfg['jumpPass'] ?? '');

        if ($jumpHost === '') {
            throw new \RuntimeException('跳板 IP（jumpHost）未配置（jump 模式必填）。');
        }

        $ssh = $this->newSsh($jumpHost, $jumpPort);

        // 选凭据（优先级：私钥内容 > 私钥文件 > 密码）
        $how = '';
        $cred = null;
        if ($keyText !== '') {
            $how = '私钥(内容)';
            $cred = $this->loadKey($keyText, $keyPass);   // 内存私钥，不落盘
        } elseif ($keyPath !== '') {
            if (!is_readable($keyPath)) {
                throw new \RuntimeException('跳板私钥文件不可读：' . $keyPath . '（chmod 600，web 用户可读）。');
            }
            $keyData = @file_get_contents($keyPath);
            if ($keyData === false || $keyData === '') {
                throw new \RuntimeException('跳板私钥读取为空：' . $keyPath);
            }
            $how = '私钥(文件)';
            $cred = $this->loadKey($keyData, $keyPass);
        } elseif ($jumpPass !== '') {
            $how = '密码';
            $cred = $jumpPass;
        } else {
            throw new \RuntimeException('跳板凭据未配置：请填 私钥内容(jumpKeyText) / 私钥路径(jumpKeyPath) / 跳板密码(jumpPass) 其一。');
        }

        if (!$this->tryLogin($ssh, $jumpUser, $cred)) {
            // 区分「连不上跳板」与「凭据被拒」：phpseclib v2 login() 在连接失败时也返回 false。
            if (method_exists($ssh, 'isConnected') && !$ssh->isConnected()) {
                throw new \RuntimeException('无法连接跳板 ' . $jumpHost . ':' . $jumpPort
                    . '（网络/防火墙/超时）。请确认本机到跳板已放行。');
            }
            throw new \RuntimeException('跳板登录失败（' . $how . '不被接受）。user=' . $jumpUser);
        }
        return $ssh;
    }

    /**
     * 尝试登录，吞掉连接/认证异常返回 false（由调用方用 isConnected() 区分连不上 vs 认证拒）。
     * @param object       $ssh
     * @param string       $user
     * @param object|string $cred 私钥对象 或 密码串
     */
    private function tryLogin($ssh, string $user, $cred): bool
    {
        try {
            return (bool) $ssh->login($user, $cred);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 新建并配置一个 phpseclib SSH2（解析 v3/v2 命名空间 + 设超时）。
     * @return object SSH2
     * @throws \RuntimeException
     */
    private function newSsh(string $host, int $port)
    {
        $timeout = (int) ($this->cfg['timeout'] ?? 30);
        $cls = $this->ssh2Class();
        // 第三参 = TCP 连接超时（连不上时不长挂；区分"连不上 vs 认证拒"见 tryLogin/isConnected）。
        $ssh = new $cls($host, $port, $timeout > 0 ? $timeout : 15);
        if (method_exists($ssh, 'setTimeout')) {
            $ssh->setTimeout($timeout > 0 ? $timeout : 30);
        }
        return $ssh;
    }

    /**
     * 加载模块自带的 phpseclib v3（若运行期还没有 v3）。让 RSA 走 rsa-sha2，过现代 OpenSSH 跳板。
     * 加载失败不致命——由 v2 兜底。幂等（已有 v3 直接返回）。
     */
    private function bootstrapPhpseclib3(): void
    {
        if (class_exists('phpseclib3\\Net\\SSH2')) {
            return; // 已有 v3（环境自带，或本方法已加载过）
        }
        $autoload = __DIR__ . '/sshv3/autoload.php';
        if (is_file($autoload)) {
            @require_once $autoload;
        }
    }

    /** 解析可用的 SSH2 类：优先 v3（自带 bundle / 环境，rsa-sha2），缺失才回退 WHMCS 自带 v2。 */
    private function ssh2Class(): string
    {
        $this->bootstrapPhpseclib3();
        if (class_exists('phpseclib3\\Net\\SSH2')) {
            return 'phpseclib3\\Net\\SSH2';
        }
        if (class_exists('phpseclib\\Net\\SSH2')) {
            return 'phpseclib\\Net\\SSH2';
        }
        throw new \RuntimeException('未找到 phpseclib（自带 v3 加载失败且环境无 v2）。');
    }

    /**
     * 加载私钥（兼容 v3 PublicKeyLoader / v2 RSA）。
     * @return object 可传给 SSH2::login() 的 key
     * @throws \RuntimeException
     */
    private function loadKey(string $keyData, string $passphrase)
    {
        try {
            // 修 HTML textarea 提交把 \n 变 \r\n 污染私钥：统一规范行尾为 \n。
            $keyData = str_replace(["\r\n", "\r"], "\n", $keyData);
            $this->bootstrapPhpseclib3(); // 确保自带 v3 已加载（v3 PublicKeyLoader → RSA 走 rsa-sha2）
            if (class_exists('phpseclib3\\Crypt\\PublicKeyLoader')) {
                $loader = 'phpseclib3\\Crypt\\PublicKeyLoader';
                return $loader::load($keyData, $passphrase !== '' ? $passphrase : false);
            }
            if (class_exists('phpseclib\\Crypt\\RSA')) {
                $rsa = new \phpseclib\Crypt\RSA();
                if ($passphrase !== '') {
                    $rsa->setPassword($passphrase);
                }
                if (!$rsa->loadKey($keyData)) {
                    throw new \RuntimeException('v2 RSA loadKey 失败（私钥格式/口令？）。');
                }
                return $rsa;
            }
            throw new \RuntimeException('未找到 phpseclib 私钥加载器。');
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('加载私钥失败：' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------------
    // 回显处理 / 报错识别 / save 校验 / 校验回读判定
    // ----------------------------------------------------------------------

    /** 去 CRLF（设备回显是 \r\n）。 */
    public function stripCrlf(string $s): string
    {
        return str_replace("\r", '', $s);
    }

    /** 在回显里找 VRP 命令级报错；命中返回该错误行，否则空串。 */
    public function findVrpError(string $cleanOutput): string
    {
        $lines = explode("\n", $cleanOutput);
        foreach ($lines as $line) {
            $l = trim($line);
            if ($l === '' || $this->isNormalDisconnect($l)) {
                continue;
            }
            foreach (self::VRP_ERROR_MARKERS as $marker) {
                if ($marker === '% ') {
                    if (strncmp($l, '%', 1) === 0) {
                        return $l;
                    }
                    continue;
                }
                if (stripos($l, $marker) !== false) {
                    return $l;
                }
            }
            if (preg_match('/^\s*\^\s*$/', $line)) {
                return '语法定位错误（^）：' . $l;
            }
        }
        return '';
    }

    private function isNormalDisconnect(string $line): bool
    {
        foreach (self::NORMAL_DISCONNECT_MARKERS as $marker) {
            if (stripos($line, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** save 是否确认成功（VRP 典型成功回显含 successfully/saved）。 */
    public function saveConfirmed(string $cleanOutput): bool
    {
        $low = strtolower($cleanOutput);
        foreach (['save the configuration successfully', 'configuration is saved',
                  'save configuration successfully', 'successfully', 'saved'] as $m) {
            if (strpos($low, $m) !== false) {
                return true;
            }
        }
        return false;
    }

    /** 某接口是否 UP（current state / protocol UP）。 */
    public function ifaceIsUp(string $displayIfaceOutput): bool
    {
        $low = strtolower($displayIfaceOutput);
        $hasUp = (strpos($low, 'current state : up') !== false)
            || (strpos($low, 'current state: up') !== false)
            || preg_match('/protocol\s+current\s+state\s*:\s*up/', $low) === 1;
        $hasDown = (strpos($low, 'current state : down') !== false)
            || (strpos($low, 'current state: down') !== false);
        return $hasUp && !$hasDown;
    }

    /**
     * 路由表里是否命中某网络。给出 $len 时**只认真实路由行的斜杠格式 `net/len`**
     * （华为路由表 Destination/Mask 列即 `a.b.c.d/NN`）。
     *
     * 🔴 绝不能退回「裸 net」匹配：`runDisplay` 的返回**含所发命令本身的回显**
     *    （`display ip routing-table NET LEN`，NET 被空格包围）。销户后设备已无该路由，
     *    但回显里仍有这行；裸 net 匹配会撞它 → 误判「路由仍在」→ verify 不过 → 不回池。
     *    斜杠版 `net/len` 不会出现在空格版命令回显里，故只认斜杠版即可区分「真路由 vs 命令回显」。
     *
     * @param int|string|null $len 掩码长度（来自 prefix）；为空则退回裸网络子串匹配（兼容旧调用）
     */
    public function routeHit(string $displayRouteOutput, string $net, $len = null): bool
    {
        if (trim($displayRouteOutput) === '') {
            return false;
        }
        $low = strtolower($displayRouteOutput);
        if (strpos($low, 'not found') !== false || strpos($low, 'no route') !== false) {
            return false;
        }
        if ($len !== null && $len !== '') {
            // 只认真实路由行的斜杠格式 net/len（命令回显是空格版 "NET LEN"，不会误命中）。
            return strpos($displayRouteOutput, $net . '/' . $len) !== false;
        }
        return strpos($displayRouteOutput, $net) !== false;
    }

    /** traffic-policy applied-record 是否含某 policy 名。 */
    public function policyApplied(string $appliedRecordOutput, string $policyName): bool
    {
        return strpos($appliedRecordOutput, $policyName) !== false;
    }

    /** 截断回显用于报错。 */
    private function snippet(string $s, int $len = 300): string
    {
        return substr(trim($this->stripCrlf($s)), 0, $len);
    }

    // ----------------------------------------------------------------------
    // 审计日志 + 配置键 + 凭据
    // ----------------------------------------------------------------------

    /** 写一条结构化审计（mod_ipdelivery_log）。失败静默。 */
    private function auditLog(int $serviceId, string $action, string $block, string $output, string $result): void
    {
        try {
            \WHMCS\Database\Capsule::table(Schema::T_LOG)->insert([
                'serviceid'     => $serviceId ?: null,
                'action'        => substr($action, 0, 32),
                'command_block' => $block,
                'device_output' => $this->stripCrlf($output),
                'result'        => $result,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 表不存在/DB 抖动：忽略（主流程仍有 logModuleCall）。
        }
    }

    /**
     * 期望的连接配置键（addon `_config` 字段名要与此对齐）。
     * @return string[]
     */
    public static function configKeys(): array
    {
        return [
            'connMode',                                   // direct | jump
            'jumpHost', 'jumpPort', 'jumpUser', 'jumpKeyPath', 'jumpKeyPassphrase', 'jumpPass', 'jumpKeyText',
            'deviceHost', 'devicePort', 'kex',
            'writeUser', 'writePass', 'readUser', 'readPass',
            'timeout',
        ];
    }

    private function writeCreds(): array
    {
        return [trim((string) ($this->cfg['writeUser'] ?? '')), (string) ($this->cfg['writePass'] ?? '')];
    }

    private function readCreds(): array
    {
        $u = trim((string) ($this->cfg['readUser'] ?? ''));
        $p = (string) ($this->cfg['readPass'] ?? '');
        if ($u === '' || $p === '') {
            return $this->writeCreds();
        }
        return [$u, $p];
    }
}
