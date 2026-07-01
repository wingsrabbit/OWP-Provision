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

/**
 * 下单库存可用性：server 形态产品在「空闲服务器不足」时**拦在结账提交**（创建订单/发票前），避免客户
 * 付款后到 CreateAccount 才失败。用 `ShoppingCartValidateCheckout`（整车触发一次、返回错误串数组即阻断）——
 * v2.6.0 误用了不存在的 `ShoppingCartValidateProduct`（WHMCS 无此 hook，回调从不执行=死代码），本版修正。
 * 整车遍历 `$_SESSION['cart']['products']`，仅约束本模块 serviceModel=server 产品；IP transit 不受约束。
 * 全程 try/catch fail-open：校验自身出错只记日志、不阻断结账（避免误杀正常下单）。
 *
 * 供需取舍（best-effort）：先按全部空闲机做**总量兜底**（总需求 > 总空闲 → 拦），再对每条具体 line 精确比对
 * （freeForLine('') 含全部空闲、freeForLine('X') 含 line=X 或无 line 的空闲；多 line 混合时不强求零重复计数）。
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    $errors = [];
    try {
        $items = $_SESSION['cart']['products'] ?? [];
        if (!is_array($items) || empty($items)) {
            return $errors;
        }
        require_once __DIR__ . '/lib/Schema.php';
        require_once __DIR__ . '/lib/Projects.php';
        require_once __DIR__ . '/lib/Lines.php';
        require_once __DIR__ . '/lib/Servers.php';
        \OwpProvision\Schema::ensureTables();

        // 累计 server 形态产品的需求台数（按 line + 总量）。
        $demandByLine = [];
        $totalDemand  = 0;
        foreach ($items as $it) {
            $pid = (int) ($it['pid'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $prod = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->first();
            if (!$prod || (string) $prod->servertype !== 'owp_provision') {
                continue;
            }
            $copt = (isset($it['configoptions']) && is_array($it['configoptions'])) ? $it['configoptions'] : [];
            $project = null;
            $cartProjectKey = owpprov_cart_option_value($pid, $copt, ['project_key', 'Project Key', 'project', 'Project']);
            $projectKey = \OwpProvision\Projects::normalizeKey($cartProjectKey !== '' ? $cartProjectKey : (string) ($prod->configoption6 ?? ''));
            if ($projectKey !== '') {
                $project = \OwpProvision\Projects::byKey($projectKey);
            }
            $isServerProject = $project && ((string) ($project->service_model ?? '') === 'server'
                || \OwpProvision\Projects::hasFeature($project, 'server_binding'));
            if (!$isServerProject && strtolower(trim((string) ($prod->configoption5 ?? ''))) !== 'server') {
                continue; // 仅 server 形态受库存约束
            }
            $qty  = max(1, (int) ($it['qty'] ?? 1));
            $line = owpprov_cart_line($pid, $copt);
            if ($line === '' && $project) {
                $line = trim((string) \OwpProvision\Projects::binding($project, 'line_name', ''));
                if ($line === '') {
                    $lineId = \OwpProvision\Projects::bindingInt($project, 'line_id', 0);
                    $lineObj = $lineId > 0 ? \OwpProvision\Lines::get($lineId) : null;
                    $line = $lineObj ? (string) $lineObj->name : '';
                }
            }
            $demandByLine[$line] = ($demandByLine[$line] ?? 0) + $qty;
            $totalDemand        += $qty;
        }
        if ($totalDemand === 0) {
            return $errors; // 车里没有 server 形态产品
        }

        // 总量兜底：总需求 > 全部空闲机 → 拦（覆盖「售罄=0」与超量）。
        $totalFree = count(\OwpProvision\Servers::freeForLine(''));
        if ($totalDemand > $totalFree) {
            $errors[] = '当前可用服务器不足（需 ' . $totalDemand . ' 台、余 ' . $totalFree
                . ' 台），请减少数量或联系销售。/ Insufficient servers available — please reduce quantity or contact sales.';
            return $errors; // 总量已不足，无需再逐 line 报
        }
        // 每条具体 line 的精确校验（'' 已并入总量校验）。
        foreach ($demandByLine as $line => $need) {
            if ($line === '') {
                continue;
            }
            $free = count(\OwpProvision\Servers::freeForLine($line));
            if ($need > $free) {
                $errors[] = '当前可用服务器不足（线路 ' . $line . '：需 ' . $need . '、余 ' . $free
                    . '），请减少数量或联系销售。/ Insufficient servers for the selected line.';
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('logModuleCall')) {
            $n = is_array($_SESSION['cart']['products'] ?? null) ? count($_SESSION['cart']['products']) : 0;
            logModuleCall('owp_provision', 'ShoppingCartValidateCheckout', ['cart_items' => $n], $e->getMessage(), '');
        }
    }
    return $errors;
});

/**
 * 从购物车条目的配置项里解析 line（best-effort）；解析不到返回 ''（→ 不限线路，按总量兜底）。
 * @param array $configoptions cart item 的 configoptions（[configoptionid => suboptionid]）
 */
function owpprov_cart_line(int $pid, array $configoptions): string
{
    if (empty($configoptions)) {
        return '';
    }
    try {
        $gids = \WHMCS\Database\Capsule::table('tblproductconfiglinks')->where('pid', $pid)->pluck('gid')->all();
        if (empty($gids)) {
            return '';
        }
        $lineOptIds = \WHMCS\Database\Capsule::table('tblproductconfigoptions')
            ->whereIn('gid', $gids)
            ->where(function ($q) {
                $q->where('optionname', 'like', '%line%')->orWhere('optionname', 'like', '%线路%');
            })->pluck('id')->all();
        foreach ($lineOptIds as $oid) {
            if (isset($configoptions[$oid]) && (int) $configoptions[$oid] > 0) {
                $sub = \WHMCS\Database\Capsule::table('tblproductconfigoptionssub')->where('id', (int) $configoptions[$oid])->first();
                if ($sub && trim((string) $sub->optionname) !== '') {
                    return trim((string) $sub->optionname);
                }
            }
        }
    } catch (\Throwable $e) {
    }
    return '';
}

/**
 * 从购物车条目的配置项里解析指定 option 名称的子选项文本。
 *
 * @param string[] $names
 */
function owpprov_cart_option_value(int $pid, array $configoptions, array $names): string
{
    if (empty($configoptions)) {
        return '';
    }
    try {
        $gids = \WHMCS\Database\Capsule::table('tblproductconfiglinks')->where('pid', $pid)->pluck('gid')->all();
        if (empty($gids)) {
            return '';
        }
        $q = \WHMCS\Database\Capsule::table('tblproductconfigoptions')->whereIn('gid', $gids);
        $q->where(function ($inner) use ($names) {
            foreach ($names as $idx => $name) {
                $method = $idx === 0 ? 'where' : 'orWhere';
                $inner->{$method}('optionname', 'like', '%' . $name . '%');
            }
        });
        $optIds = $q->pluck('id')->all();
        foreach ($optIds as $oid) {
            if (isset($configoptions[$oid]) && (int) $configoptions[$oid] > 0) {
                $sub = \WHMCS\Database\Capsule::table('tblproductconfigoptionssub')->where('id', (int) $configoptions[$oid])->first();
                if ($sub && trim((string) $sub->optionname) !== '') {
                    return trim((string) $sub->optionname);
                }
            }
        }
    } catch (\Throwable $e) {
    }
    return '';
}

/**
 * P1 异步开通处理器：每个系统 cron 周期（AfterCronJob，推荐每 5 分钟）扫开通队列，逐单跑真机编排。
 * worker 把 $GLOBALS['__owp_async_run'] 置为该 serviceid 后**直接重入** owp_provision_CreateAccount($params)
 * （非 ModuleCreate→不重发欢迎邮件），用存的加密 payload 跑真活；沿用 Orchestrator 全局锁串行 + 失败回滚。
 * 顺手 purge 7 天前 oplog。fail-open：异常只记日志、不影响其它 cron 任务。
 */
add_hook('AfterCronJob', 1, function ($vars) {
    try {
        require_once __DIR__ . '/owp_provision.php'; // 载入模块函数 owp_provision_* + 全部 lib
        \OwpProvision\Schema::ensureTables();
        try { \OwpProvision\Orchestrator::purgeOplog(); } catch (\Throwable $e) {}

        foreach (\OwpProvision\Jobs::due() as $job) {
            $sid    = (int) $job->serviceid;
            $params = \OwpProvision\Jobs::payload($sid);
            if (!is_array($params)) {
                \OwpProvision\Jobs::markFailed($sid, '任务 payload 丢失或无法解析，请人工 Create。');
                continue;
            }
            \OwpProvision\Jobs::markRunning($sid);
            $GLOBALS['__owp_async_run'] = $sid; // 标志：让重入的 CreateAccount 跑真活而非再次入队
            try {
                $r = owp_provision_CreateAccount($params);
                if (is_string($r) && strtolower(trim($r)) === 'success') {
                    \OwpProvision\Jobs::markDone($sid);
                } else {
                    \OwpProvision\Jobs::markFailed($sid, is_string($r) ? $r : 'unknown');
                }
            } catch (\Throwable $e) {
                \OwpProvision\Jobs::markFailed($sid, $e->getMessage());
            } finally {
                unset($GLOBALS['__owp_async_run']);
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('owp_provision', 'AfterCronJob', [], $e->getMessage(), '');
        }
    }
});
