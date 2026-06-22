{*
    IP-Delivery — Client Area template (GRE 改对端)
    -------------------------------------------------------------------------
    由 owp_provision_ClientArea() 渲染。变量见该函数 $vars。
    仅 GRE 服务显示「改对端」表单；XC 只读展示。
    提交回 clientarea.php?action=productdetails&id={serviceid}，POST ipd_action=change_remote。
    {$ipd_token} 是本模块的 CSRF nonce，必须带上。
*}

<div class="ipd-wrap">
    <h3>IP 交付详情 / IP Delivery</h3>

    {if $error}
        <div class="alert alert-danger">{$error}</div>
    {/if}
    {if $message}
        <div class="alert alert-success">{$message}</div>
    {/if}

    <table class="table table-striped">
        <tbody>
            <tr>
                <td style="width:40%"><strong>交付方式 / Type</strong></td>
                <td>{$deliveryType}</td>
            </tr>
            <tr>
                <td><strong>交付网段 / Delivered Prefix</strong></td>
                <td><code>{$prefix}</code></td>
            </tr>
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
            {else}
                <tr>
                    <td><strong>PTP（我方 / 您侧）</strong></td>
                    <td><code>{$ptpOur}</code> / <code>{$ptpPeer}</code></td>
                </tr>
            {/if}
        </tbody>
    </table>

    {if $hasVpn}
        <h4>IPMI VPN 接入 / Remote IPMI access</h4>
        <table class="table table-striped">
            <tbody>
                <tr><td style="width:40%"><strong>VPN 用户名 / Username</strong></td><td><code>{$vpnUser}</code></td></tr>
                <tr><td><strong>分配地址 / Your VPN IP</strong></td><td><code>{$vpnIp}</code></td></tr>
                <tr><td><strong>可达 IPMI / Reachable IPMI</strong></td><td><code>{$vpnTarget}</code></td></tr>
                <tr><td><strong>支持协议 / Protocols</strong></td><td>L2TP / PPTP / SSTP / OpenVPN / IKEv2</td></tr>
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
        <div class="alert alert-info" style="font-size:12px">
            连上 VPN 后仅可访问<strong>您自己的 IPMI</strong> 与<strong>公网</strong>（用于下载/安装系统），其余网络已隔离。请通过 IPMI/iDRAC 自行安装操作系统。<br>
            Once connected you may reach only your own IPMI and the public internet; everything else is isolated.
        </div>
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
</div>
