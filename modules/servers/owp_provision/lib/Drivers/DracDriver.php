<?php
/**
 * OWP Provision — Drivers/DracDriver.php  (v2 · iDRAC/BMC via Redfish)
 * ----------------------------------------------------------------------------
 * 给租赁服务器的 iDRAC 建/删**最小权限客户子账号**（Redfish over HTTPS）。
 *
 * 可达性：iDRAC 在 IPMI 内网，WHMCS（公网）直连不到 → 开通时由 RosDriver 在 ROS 上开一条**临时
 * DNAT**（只放行 WHMCS 源 IP：公网:pubPort → iDRAC:443），WHMCS 经此基址走 Redfish，用完即撤。
 * 故本类只认一个 base URL（`https://<ROS公网>:<pubPort>`），不关心底层 NAT。
 *
 * iDRAC9 账号是固定槽位（1-16，1-2 保留）：建号 = 找空槽 PATCH（UserName/Password/RoleId/Enabled）；
 * 删号 = 按 UserName 找槽 PATCH 清空（Enabled=false, UserName=""）。给客户的角色默认 `Operator`
 * （可用虚拟介质/KVM/电源，不可管用户/改 iDRAC）。自签证书 → 不校验 TLS。
 *
 * 真机 Redfish 细节因 iDRAC 版本而异，由运维在真机验收/微调（命令/字段集中在本类）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3 · iDRAC9 Redfish
 */

namespace OwpProvision\Drivers;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class DracDriver
{
    private string $base;        // https://<host>:<port>（指向 ROS 临时 DNAT 前端）
    private string $adminUser;
    private string $adminPass;
    private bool $dryRun;
    private int $timeout = 20;
    private string $hostHeader = '';   // iDRAC 真实地址：覆盖 Host 头（DELL iDRAC 校验 Host，不符回 400）
    private string $lastErr   = '';    // accounts() 最近一次「列举失败」的 HTTP/错误（脱敏）；列举成功则空

    /**
     * @param string $baseUrl    Redfish 基址（经 ROS 临时 DNAT，如 https://<ROS公网>:<pubPort>）
     * @param string $hostHeader iDRAC 真实地址（ipmiTarget）。非空时显式覆盖 Host 头——DELL iDRAC 校验
     *                           Host，若是 DNAT 前端地址会回 HTTP 400；URL 仍走 DNAT 前端，仅改 Host。
     */
    public function __construct(string $baseUrl, string $adminUser, string $adminPass, bool $dryRun = false, string $hostHeader = '')
    {
        $this->base       = rtrim($baseUrl, '/');
        $this->adminUser  = $adminUser;
        $this->adminPass  = $adminPass;
        $this->dryRun     = $dryRun;
        $this->hostHeader = trim($hostHeader);
    }

    /** 建客户子账号：找空槽 PATCH 写入。返回 ['ok','slot','error']。 */
    public function createUser(string $username, string $password, string $role = 'Operator'): array
    {
        if ($this->dryRun) {
            return ['ok' => true, 'slot' => 0, 'error' => '', 'dryRun' => true];
        }
        if ($username === '' || $password === '') {
            return ['ok' => false, 'slot' => 0, 'error' => '用户名/密码为空'];
        }
        // 先删同名（幂等），再找空槽
        $this->deleteUser($username);
        $slot = $this->firstEmptySlot();
        if ($slot <= 0) {
            // 区分「够不到 iDRAC」与「真的槽位满」——避免把可达性问题误报成槽位满（极误导）。
            if ($this->lastErr !== '') {
                return ['ok' => false, 'slot' => 0,
                        'error' => '无法访问 iDRAC Redfish（' . $this->lastErr . '）；非槽位问题，请查临时 DNAT(src-nat)/Host 头/凭据'];
            }
            return ['ok' => false, 'slot' => 0, 'error' => 'iDRAC 无空闲账号槽位（3-16 已满）'];
        }
        $body = ['UserName' => $username, 'Password' => $password, 'RoleId' => $role, 'Enabled' => true];
        $r = $this->redfish('PATCH', '/redfish/v1/AccountService/Accounts/' . $slot, $body);
        if ($r['code'] >= 200 && $r['code'] < 300) {
            return ['ok' => true, 'slot' => $slot, 'error' => ''];
        }
        return ['ok' => false, 'slot' => $slot, 'error' => 'PATCH 账号失败 HTTP ' . $r['code'] . '：' . mb_substr($r['body'], 0, 200)];
    }

    /** 删客户子账号：按 UserName 找槽 PATCH 清空。返回 ['ok','error']。 */
    public function deleteUser(string $username): array
    {
        if ($this->dryRun) {
            return ['ok' => true, 'error' => '', 'dryRun' => true];
        }
        $slot = $this->slotByUser($username);
        if ($slot <= 0) {
            return ['ok' => true, 'error' => '']; // 不存在即视为已删
        }
        $body = ['Enabled' => false, 'UserName' => ''];
        $r = $this->redfish('PATCH', '/redfish/v1/AccountService/Accounts/' . $slot, $body);
        return ['ok' => ($r['code'] >= 200 && $r['code'] < 300), 'error' => $r['code'] >= 300 ? ('HTTP ' . $r['code']) : ''];
    }

    public function testRedfish(): array
    {
        if ($this->dryRun) {
            return ['ok' => true, 'error' => '', 'dryRun' => true];
        }
        $r = $this->redfish('GET', '/redfish/v1/AccountService/Accounts');
        return ['ok' => ($r['code'] >= 200 && $r['code'] < 300), 'error' => $r['code'] >= 300 ? ('HTTP ' . $r['code']) : ''];
    }

    // ----------------------------------------------------------------------

    /** 列账号，返回 [slotId => UserName]（仅可解析的）。列举失败时置 lastErr 并返回空数组（区别于「成功但无空槽」）。 */
    private function accounts(): array
    {
        $this->lastErr = '';
        $out  = [];
        $list = $this->redfish('GET', '/redfish/v1/AccountService/Accounts');
        if ($list['code'] < 200 || $list['code'] >= 300) {
            $this->lastErr = $this->httpErr($list); // 够不到/被拒：记下真因，别让上层误判成「槽位满」
            return $out;
        }
        $data = json_decode($list['body'], true);
        $members = is_array($data) && isset($data['Members']) ? $data['Members'] : [];
        foreach ($members as $m) {
            $uri = is_array($m) ? ($m['@odata.id'] ?? '') : '';
            if ($uri === '') {
                continue;
            }
            $id = (int) preg_replace('#.*/#', '', rtrim($uri, '/'));
            $acc = $this->redfish('GET', $uri);
            if ($acc['code'] >= 200 && $acc['code'] < 300) {
                $ad = json_decode($acc['body'], true);
                $out[$id] = is_array($ad) ? (string) ($ad['UserName'] ?? '') : '';
            }
        }
        return $out;
    }

    private function firstEmptySlot(): int
    {
        foreach ($this->accounts() as $id => $user) {
            if ($id >= 3 && trim($user) === '') { // 1-2 保留给 root/系统
                return $id;
            }
        }
        return 0;
    }

    private function slotByUser(string $username): int
    {
        foreach ($this->accounts() as $id => $user) {
            if (strcasecmp(trim($user), $username) === 0) {
                return $id;
            }
        }
        return 0;
    }

    /** 把失败的 Redfish 结果格式化成脱敏错误串（HTTP 码 + 短回显；000=连接失败/超时）。 */
    private function httpErr(array $r): string
    {
        $code = (int) ($r['code'] ?? 0);
        if ($code === 0) {
            return 'HTTP 000（连接失败/超时；多为临时 DNAT 缺 src-nat 或不可达）';
        }
        $snippet = trim((string) preg_replace('/\s+/', ' ', mb_substr((string) ($r['body'] ?? ''), 0, 160)));
        return 'HTTP ' . $code . ($snippet !== '' ? '：' . $snippet : '');
    }

    /** 发一个 Redfish 请求。返回 ['code'=>int,'body'=>string]。自签证书不校验。 */
    private function redfish(string $method, string $path, ?array $body = null): array
    {
        $url = strpos($path, 'http') === 0 ? $path : ($this->base . $path);
        $ch  = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($this->hostHeader !== '') {
            // URL 走 DNAT 前端，但 Host 头显式设为 iDRAC 真实地址（DELL iDRAC 校验 Host，不符回 400）。
            $headers[] = 'Host: ' . $this->hostHeader;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $this->adminUser . ':' . $this->adminPass,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['code' => 0, 'body' => 'curl: ' . $err];
        }
        return ['code' => $code, 'body' => (string) $resp];
    }
}
