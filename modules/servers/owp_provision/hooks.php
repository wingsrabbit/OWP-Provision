<?php
/**
 * IP-Delivery — 下单交互 hooks（由 addon 的 hooks.php 桥接 require；WHMCS 不自动加载 server hooks）。
 * ============================================================================
 * 1) AJAX `clientarea.php?ipd_ajax=freeports&device=ID`：登录态、只读，返回 **该设备** port 池当前空闲端口名。
 * 2) AJAX `clientarea.php?ipd_ajax=types`：返回前端开放/启用的交付类型（供 JS 过滤 delivery_type）。
 * 3) AJAX `clientarea.php?ipd_ajax=nodes`：返回启用设备列表（id+name，供 JS 节点联动/单设备免选）。
 * 4) 购物车配置页注入 JS：
 *    - 按「前端开放类型」过滤 delivery_type 子选项（当前只 XC；GRE pending 不让选）；
 *    - 条件显隐：XC→显示端口下拉(AJAX 空闲口)+隐藏 EP IP(去必填)；GRE→隐藏端口+显示 EP IP(必填)；
 *    - 节点联动：单设备时隐藏 node 选项；多设备时换节点 → 重拉该设备空闲端口。
 *
 * 不碰设备/生命周期/自动分配。后端兜底在 server 模块 CreateAccount（Types enabled/frontend 校验 + 设备校验）。
 * 部署：本文件由 addon 的 hooks.php 桥接加载；改动后 deactivate→activate addon 刷新 hook 缓存。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

if (!defined('WHMCS')) {
    die('Access Denied');
}

/** AJAX 端点：命中 ?ipd_ajax=freeports|types|nodes 即接管并 exit。lib 惰性 require（__DIR__ = server 模块目录）。 */
add_hook('ClientAreaPage', 1, function ($vars) {
    $act = $_GET['ipd_ajax'] ?? '';
    if ($act !== 'freeports' && $act !== 'types' && $act !== 'nodes') {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    require_once __DIR__ . '/lib/Schema.php';
    require_once __DIR__ . '/lib/Config.php';
    require_once __DIR__ . '/lib/Devices.php';
    require_once __DIR__ . '/lib/Types.php';
    require_once __DIR__ . '/lib/Ipam.php';
    require_once __DIR__ . '/lib/Resources.php';

    if ($act === 'types') {
        // 类型列表只读、非敏感。
        echo json_encode([
            'ok'       => true,
            'frontend' => \OwpProvision\Types::frontendKeys(),
            'all'      => \OwpProvision\Types::enabledKeys(),
        ]);
        exit;
    }

    if ($act === 'nodes') {
        // 启用设备列表只读、非敏感（只回 id+name，便于 JS 单设备免选/节点联动）。
        try {
            \OwpProvision\Schema::ensureTables();
            $nodes = [];
            foreach (\OwpProvision\Devices::enabled() as $d) {
                $nodes[] = ['id' => (int) $d->id, 'name' => (string) $d->name];
            }
            echo json_encode(['ok' => true, 'nodes' => $nodes]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server_error', 'nodes' => []]);
        }
        exit;
    }

    // freeports：登录态校验；只回端口名，无占用明细/他客数据。按设备隔离。
    if (empty($_SESSION['uid'])) {
        echo json_encode(['ok' => false, 'error' => 'login_required', 'ports' => []]);
        exit;
    }
    try {
        \OwpProvision\Schema::ensureTables();
        $deviceId = owpprov_hook_device_id((string) ($_GET['device'] ?? ''));
        if ($deviceId <= 0) {
            // 多设备且未指定节点 → 无法确定端口池。
            echo json_encode(['ok' => false, 'error' => 'no_device', 'ports' => []]);
            exit;
        }
        echo json_encode(['ok' => true, 'device' => $deviceId, 'ports' => \OwpProvision\Ipam::freePorts($deviceId)]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server_error', 'ports' => []]);
    }
    exit;
});

/**
 * 把 AJAX 的 device 入参（id / dev{id} / 设备名）映射成设备 id；空且单设备 → 默认设备。
 * 自包含在 hooks（不依赖 server 模块函数已加载）。
 */
function owpprov_hook_device_id(string $sel): int
{
    $sel = trim($sel);
    if ($sel === '') {
        return \OwpProvision\Devices::defaultId(); // 单设备免选
    }
    if (ctype_digit($sel)) {
        $id = (int) $sel;
        return \OwpProvision\Devices::exists($id) ? $id : 0;
    }
    if (preg_match('/#(\d+)/', $sel, $m)) { // 友好标签含 id，如「Edge-A #1」
        $id = (int) $m[1];
        return \OwpProvision\Devices::exists($id) ? $id : 0;
    }
    if (preg_match('/^dev(\d+)$/i', $sel, $m)) {
        $id = (int) $m[1];
        return \OwpProvision\Devices::exists($id) ? $id : 0;
    }
    if (preg_match('/^(\d+)\s*[|:]/', $sel, $m)) {
        $id = (int) $m[1];
        return \OwpProvision\Devices::exists($id) ? $id : 0;
    }
    foreach (\OwpProvision\Devices::enabled() as $d) {
        if (strcasecmp(trim((string) $d->name), $sel) === 0) {
            return (int) $d->id;
        }
    }
    return 0;
}

/** 购物车配置页页脚注入交互 JS（仅 cart 页；JS 找不到 delivery_type 即 no-op）。 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $isCart = (strpos($uri, 'cart.php') !== false) || (($vars['filename'] ?? '') === 'cart');
    if (!$isCart) {
        return '';
    }
    return <<<'HTML'
<script>
/* IP-Delivery 下单交互：过滤前端开放类型 + 条件显隐 + XC 空闲端口下拉。找不到 delivery_type 即 no-op。 */
(function () {
    function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }
    ready(function () {
        function txt(el){ return ((el && (el.textContent||el.innerText)) || '').trim().toLowerCase(); }
        function groupOf(el){ return (el && el.closest) ? el.closest('.form-group,.row,fieldset,li,tr,div') : (el?el.parentNode:null); }
        function findField(keywords){
            var nodes = document.querySelectorAll('label,.control-label,legend,th,strong,p,span');
            for (var i=0;i<nodes.length;i++){
                var t = txt(nodes[i]); if(!t) continue;
                for (var k=0;k<keywords.length;k++){
                    if (t.indexOf(keywords[k]) !== -1){
                        var g = groupOf(nodes[i]);
                        var c = g ? g.querySelector('select,input[type=text],textarea,input[type=radio]') : null;
                        if (c) return { group:g, control:c };
                    }
                }
            }
            return null;
        }
        function ajaxGet(url, cb){
            var x=new XMLHttpRequest(); x.open('GET',url,true);
            x.onreadystatechange=function(){ if(x.readyState!==4) return; var j=null; try{ j=JSON.parse(x.responseText); }catch(e){} cb(j); };
            x.send();
        }

        var dt = findField(['delivery_type','delivery type','交付']);
        if (!dt || !dt.control) { return; } /* 非本产品配置页 → 不动 */
        var ep = findField(['remote endpoint','endpoint ip','对端 ip','gre 对端','对端']);
        var pt = findField(['xc port','xc 端口','端口']);
        var nd = findField(['node','节点','设备']); /* 节点/设备选择（Configurable Option 下拉，可能不存在=单设备） */

        function currentType(){
            var c = dt.control;
            if (c.tagName === 'SELECT'){
                var v = c.value || (c.options[c.selectedIndex] ? c.options[c.selectedIndex].text : '');
                return (v||'').toUpperCase();
            }
            var rs = dt.group ? dt.group.querySelectorAll('input[type=radio]') : [];
            for (var i=0;i<rs.length;i++){ if (rs[i].checked) return (rs[i].value||'').toUpperCase(); }
            return (c.value||'').toUpperCase();
        }
        /* 当前所选节点：取「可见文本」（= 后端 configoptions 收到的子选项名），剥掉 WHMCS 可能追加的尾部价格 (+$..)。 */
        function nodeClean(s){ return String(s||'').replace(/\s*\([^)]*\)\s*$/,'').trim(); }
        function currentNode(){
            if (!nd || !nd.control) return '';
            var c = nd.control;
            if (c.tagName === 'SELECT'){ var o=c.options[c.selectedIndex]; return nodeClean(o?(o.text||o.value):''); }
            var rs = nd.group ? nd.group.querySelectorAll('input[type=radio]') : [];
            for (var i=0;i<rs.length;i++){ if (rs[i].checked) return nodeClean(rs[i].value); }
            return nodeClean(c.value);
        }
        function show(g,on){ if (g) g.style.display = on ? '' : 'none'; }

        /* 只保留「前端开放类型」的 delivery_type 子选项（open=小写 key 数组）。取不到/不匹配则不动（后端兜底）。 */
        function filterTypes(open){
            if (!open || !open.length) return;
            var c = dt.control;
            if (c.tagName === 'SELECT'){
                var anyOpen=false, firstVis=null, selHidden=false;
                for (var i=0;i<c.options.length;i++){
                    var o=c.options[i], key=(o.value||'').trim().toLowerCase();
                    if (key==='') continue;
                    if (open.indexOf(key)!==-1){ o.hidden=false; o.disabled=false; anyOpen=true; if(!firstVis) firstVis=o; }
                    else { o.hidden=true; o.disabled=true; if(o.selected) selHidden=true; }
                }
                if (anyOpen && selHidden && firstVis){ c.value=firstVis.value; }
            } else {
                var rs=dt.group?dt.group.querySelectorAll('input[type=radio]'):[], anyOpen=false, firstVis=null, selHidden=false;
                for (var i=0;i<rs.length;i++){
                    var key=(rs[i].value||'').trim().toLowerCase();
                    var row=(rs[i].closest&&rs[i].closest('label,.radio,li,div'))||rs[i].parentNode;
                    if (open.indexOf(key)!==-1){ if(row) row.style.display=''; rs[i].disabled=false; anyOpen=true; if(!firstVis) firstVis=rs[i]; }
                    else { if(row) row.style.display='none'; rs[i].disabled=true; if(rs[i].checked) selHidden=true; }
                }
                if (anyOpen && selHidden && firstVis){ firstVis.checked=true; }
            }
        }

        /* 端口下拉：建一次（隐藏原 input），按当前节点拉空闲端口；换节点可重拉。 */
        var portSel=null, portInput=null;
        function ensurePortSelect(){
            if (portSel || !pt || !pt.control) return;
            portInput = pt.control;
            portSel = document.createElement('select');
            portSel.className = portInput.className || 'form-control';
            portSel.id = 'ipd_xc_port_select';
            portSel.innerHTML = '<option value="">加载空闲端口…</option>';
            portInput.style.display = 'none';
            portInput.parentNode.insertBefore(portSel, portInput);
            portSel.addEventListener('change', function(){ portInput.value = portSel.value; });
        }
        function loadPorts(){
            if (!portSel) return;
            portSel.innerHTML = '<option value="">加载空闲端口…</option>';
            var dev = encodeURIComponent(currentNode());
            ajaxGet('clientarea.php?ipd_ajax=freeports&device='+dev+'&_=' + Date.now(), function(j){
                var ok=!!(j&&j.ok), ports=(j&&j.ports)||[];
                if (!ok){
                    var msg = (j&&j.error==='no_device') ? '请先选择节点 / Select node first' : '暂不可用（请登录或联系客服）';
                    portSel.innerHTML='<option value="">'+msg+'</option>'; if(portInput) portInput.value=''; return;
                }
                if (!ports.length){ portSel.innerHTML='<option value="">暂无空闲端口，请联系客服</option>'; if(portInput) portInput.value=''; return; }
                var h='<option value="">请选择端口 / Select port</option>';
                for (var i=0;i<ports.length;i++){ var p=String(ports[i]).replace(/[^A-Za-z0-9\/_.\-]/g,''); h+='<option value="'+p+'">'+p+'</option>'; }
                portSel.innerHTML=h;
                if (portInput && portInput.value){ portSel.value=portInput.value; }
            });
        }

        function apply(){
            var t=currentType();
            var isXc=t.indexOf('XC')!==-1, isGre=t.indexOf('GRE')!==-1;
            if (ep){ show(ep.group, isGre); if(ep.control){ if(isGre){ep.control.setAttribute('required','required');} else {ep.control.removeAttribute('required');} } }
            if (pt){ show(pt.group, isXc); if(isXc){ ensurePortSelect(); loadPorts(); } }
        }

        if (dt.control.tagName==='SELECT'){ dt.control.addEventListener('change', apply); }
        var rs=dt.group?dt.group.querySelectorAll('input[type=radio]'):[];
        for (var i=0;i<rs.length;i++){ rs[i].addEventListener('change', apply); }

        /* 节点联动：换节点 → 若当前是 XC，重拉该节点空闲端口（端口池按设备隔离）。 */
        if (nd && nd.control){
            if (nd.control.tagName==='SELECT'){ nd.control.addEventListener('change', function(){ if(currentType().indexOf('XC')!==-1){ loadPorts(); } }); }
            var nrs = nd.group ? nd.group.querySelectorAll('input[type=radio]') : [];
            for (var i=0;i<nrs.length;i++){ nrs[i].addEventListener('change', function(){ if(currentType().indexOf('XC')!==-1){ loadPorts(); } }); }
        }
        /* 单设备免选：启用设备 ≤1 → 隐藏 node 选项（后端默认用唯一设备）。 */
        ajaxGet('clientarea.php?ipd_ajax=nodes&_=' + Date.now(), function(j){
            if (j && j.ok && j.nodes && nd && nd.group && j.nodes.length <= 1){ show(nd.group, false); }
        });

        /* 先按前端开放类型过滤，再应用显隐；types 取不到则只做显隐（后端仍兜底） */
        ajaxGet('clientarea.php?ipd_ajax=types&_=' + Date.now(), function(j){
            if (j && j.ok && j.frontend){ filterTypes(j.frontend); }
            apply();
        });
        apply();
    });
})();
</script>
HTML;
});
