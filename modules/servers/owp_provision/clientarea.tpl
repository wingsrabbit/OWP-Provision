{*
    OWP Provision — Client Area template (GRE 改对端)
    -------------------------------------------------------------------------
    由 owp_provision_ClientArea() 渲染。变量见该函数 $vars。
    仅 GRE 服务显示「改对端」表单；XC 只读展示。
    提交回 clientarea.php?action=productdetails&id={serviceid}，POST ipd_action=change_remote。
    {$ipd_token} 是本模块的 CSRF nonce，必须带上。
*}

<div class="ipd-wrap">
    <h3>交付详情 / OWP Provision</h3>

    {if $error}
        <div class="alert alert-danger">{$error}</div>
    {/if}
    {if $message}
        <div class="alert alert-success">{$message}</div>
    {/if}

    {if $provisioning}
        <div class="alert alert-info">
            <strong>⏳ 开通中… / Provisioning…</strong><br>
            正在自动为您配置交换机、VPN 与 IPMI，预计数分钟内完成；完成后此页将显示交付网段、VPN 与 iDRAC 登录信息。请稍后刷新本页。<br>
            We're provisioning your service (switch + VPN + IPMI). This page will show your connection details once it's done.
            {if $provStep}<div style="margin-top:8px;font-size:12px;color:#888">当前步骤 / current step：<code>{$provStep}</code></div>{/if}
        </div>
    {else}

    <table class="table table-striped">
        <tbody>
            <tr>
                <td style="width:40%"><strong>交付方式 / Type</strong></td>
                <td>{$deliveryType}</td>
            </tr>
            {if $deliveryType != 'VPN'}
                <tr>
                    <td><strong>交付网段 / Delivered Prefix</strong></td>
                    <td><code>{$prefix}</code></td>
                </tr>
            {/if}
            {if $deliveryType == 'GRE'}
                <tr>
                    <td><strong>隧道 / Tunnel</strong></td>
                    <td>Tunnel{$tunnelId}（源 Loopback {$loopback}）</td>
                </tr>
                <tr>
                    <td><strong>Transit /30（我方 / 您侧）</strong></td>
                    <td><code>{$ptpOur}</code> / <code>{$ptpPeer}</code></td>
                </tr>
                <tr>
                    <td><strong>当前对端 / Current Remote</strong></td>
                    <td><code>{$remoteIp}</code></td>
                </tr>
                <tr>
                    <td><strong>隧道状态 / Tunnel State</strong></td>
                    <td>
                        {if $tunnelState == 'UP'}
                            <span class="label label-success" style="color:#3c763d;font-weight:bold">UP</span>
                        {else}
                            <span class="label label-warning" style="color:#a94442;font-weight:bold">{$tunnelState}</span>
                        {/if}
                    </td>
                </tr>
            {elseif $isServer}
                <tr>
                    <td><strong>网关 / Gateway</strong></td>
                    <td><code>{$gateway}</code></td>
                </tr>
                <tr>
                    <td><strong>子网掩码 / Netmask</strong></td>
                    <td><code>{$netmask}</code></td>
                </tr>
                <tr>
                    <td><strong>可用 IP / Usable IPs</strong></td>
                    <td><code>{$usableRange}</code></td>
                </tr>
                {if $ipv6Prefixes|@count > 0}
                    <tr>
                        <td><strong>IPv6 前缀 / IPv6 Prefixes</strong></td>
                        <td>
                            {foreach from=$ipv6Prefixes item=p}
                                <div><code>{$p}</code></div>
                            {/foreach}
                        </td>
                    </tr>
                {/if}
            {elseif $deliveryType != 'VPN'}
                <tr>
                    <td><strong>PTP（我方 / 您侧）</strong></td>
                    <td><code>{$ptpOur}</code> / <code>{$ptpPeer}</code></td>
                </tr>
            {/if}
        </tbody>
    </table>
    {if $isServer}
        <div class="alert alert-info" style="font-size:12px">
            把上面任一<strong>可用 IP</strong> 配到服务器网卡，网关填 <code>{$gateway}</code>、掩码 <code>{$netmask}</code>。<br>
            Configure one of the usable IPs on your server NIC; gateway = {$gateway}, netmask = {$netmask}.
        </div>
    {/if}

    {if $hasVpn}
        <h4>{if $vpnTarget}IPMI VPN 接入 / Remote IPMI access{else}VPN 接入 / Remote access{/if}</h4>
        <table class="table table-striped">
            <tbody>
                {if $vpnServer}
                    <tr><td style="width:40%"><strong>VPN 服务器 / Server</strong></td><td><code>{$vpnServer}</code></td></tr>
                {/if}
                <tr><td style="width:40%"><strong>VPN 用户名 / Username</strong></td><td><code>{$vpnUser}</code></td></tr>
                <tr><td><strong>分配地址 / Your VPN IP</strong></td><td><code>{$vpnIp}</code></td></tr>
                {if $vpnTarget}
                    <tr><td><strong>可达 IPMI / Reachable IPMI</strong></td><td><code>{$vpnTarget}</code></td></tr>
                {/if}
                <tr><td><strong>支持协议 / Protocols</strong></td><td>{$vpnProtocols}</td></tr>
                {if $ipsecPsk}
                    <tr><td><strong>IPsec 预共享密钥 / PSK</strong></td><td><code>{$ipsecPsk}</code><div style="font-size:12px;color:#888">L2TP/IPsec、IKEv2 连接时填此共享密钥</div></td></tr>
                {/if}
                <tr>
                    <td><strong>密码 / Password</strong></td>
                    <td>
                        {if $vpnPass}
                            <code style="font-size:14px">{$vpnPass}</code>
                            <div class="alert alert-warning" style="margin-top:6px;font-size:12px">⚠ 仅此一次显示，请立即保存 / Shown only once — save it now.</div>
                        {elseif $vpnRevealed}
                            <span style="color:#888">•••••••• （已查看过；如忘记请联系客服重置 / already viewed）</span>
                        {else}
                            <form method="post" action="{$modulelink}" style="display:inline">
                                <input type="hidden" name="ipd_token" value="{$ipd_token}" />
                                <input type="hidden" name="ipd_action" value="reveal_vpn" />
                                <button type="submit" class="btn btn-default btn-sm"
                                        onclick="return confirm('VPN 密码仅可查看一次，确认现在查看并保存？');">查看一次 / Reveal once</button>
                            </form>
                        {/if}
                    </td>
                </tr>
            </tbody>
        </table>
        {if $vpnTarget}
            <div class="alert alert-info" style="font-size:12px">
                连上 VPN 后仅可访问<strong>您自己的 IPMI</strong> 与<strong>公网</strong>（用于下载/安装系统），其余网络已隔离。请通过 IPMI/iDRAC 自行安装操作系统。<br>
                Once connected you may reach only your own IPMI and the public internet; everything else is isolated.
            </div>
        {else}
            <div class="alert alert-info" style="font-size:12px">
                这是独立 VPN 服务。连接后会分配上方固定地址，具体可访问范围以服务说明和防火墙策略为准。<br>
                This standalone VPN service assigns the fixed address above; reachable networks follow the service policy.
            </div>
        {/if}

        {if $idracUrl}
            <h4>iDRAC 远程管理 / Out-of-band</h4>
            <table class="table table-striped">
                <tbody>
                    <tr><td style="width:40%"><strong>iDRAC 网页 / Web</strong></td><td><a href="{$idracUrl}" target="_blank" rel="noopener"><code>{$idracUrl}</code></a>（经 VPN 访问 / via VPN）</td></tr>
                    {if $idracBuilt}
                        <tr><td><strong>iDRAC 用户名 / Username</strong></td><td><code>{$idracUser}</code></td></tr>
                        <tr><td><strong>iDRAC 密码 / Password</strong></td><td>同上方 VPN 密码 / same as your VPN password</td></tr>
                    {/if}
                </tbody>
            </table>
            {if $idracBuilt}
                <div class="alert alert-info" style="font-size:12px">
                    连上 VPN 后，浏览器打开 <code>{$idracUrl}</code>，用上面的 iDRAC 用户名/密码登录，即可远程开关机、挂载 ISO、装系统（虚拟介质 / KVM / 电源）。<br>
                    After connecting the VPN, open the iDRAC URL and log in with the credentials above for remote KVM / virtual media / power.
                </div>
            {else}
                <div class="alert alert-warning" style="font-size:12px">
                    iDRAC 子账号将由客服为您开通后提供；如急需请联系我们。/ Your iDRAC account will be provisioned by support — contact us if urgent.
                </div>
            {/if}
        {/if}
    {/if}

    {if $deliveryType == 'GRE'}
        <h4>您侧 GRE 配置参考 / Your-side config</h4>
        <pre style="white-space:pre-wrap;background:#f7f7f9;border:1px solid #e1e1e8;padding:10px;border-radius:4px">{$configHint}</pre>

        <h4>更改对端 IP / Change Remote Endpoint</h4>
        <div class="alert alert-warning" style="font-size:13px">
            ⚠ 切换对端会瞬断隧道（短暂中断），请在您侧设备同步更新后再提交。每 {$cooldownMins} 分钟最多变更一次。<br>
            Changing the remote endpoint will briefly drop the tunnel. Limited to once per {$cooldownMins} minutes.
        </div>

        <form method="post" action="{$modulelink}">
            <input type="hidden" name="ipd_token" value="{$ipd_token}" />
            <input type="hidden" name="ipd_action" value="change_remote" />
            <div class="form-group" style="max-width:360px">
                <label for="ipd_remote_ip">新对端公网 IPv4 / New Remote IPv4</label>
                <input type="text" class="form-control" id="ipd_remote_ip" name="ipd_remote_ip"
                       value="{$remoteIp}" placeholder="e.g. 203.0.113.10" required
                       pattern="^((25[0-5]|2[0-4]\d|1?\d?\d)\.){literal}{3}{/literal}(25[0-5]|2[0-4]\d|1?\d?\d)$" />
            </div>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('确认更改对端 IP？这会瞬断隧道。');">
                更新对端 / Update Remote
            </button>
        </form>
    {/if}
    {/if}{* /provisioning else *}
</div>
