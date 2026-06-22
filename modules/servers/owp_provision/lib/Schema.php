<?php
/**
 * IP-Delivery — Schema.php
 * ----------------------------------------------------------------------------
 * 建表 / 迁移（idempotent）。被 addon `_activate()` 调用，server 模块在每次
 * 生命周期函数入口也可调用 ensureTables() 兜底（防止只装了 server 没 Activate addon）。
 *
 * 全部走 WHMCS\Database\Capsule —— 不自建 PDO/mysqli，不硬编码 DB 凭据。
 * 表：InnoDB / utf8mb4，前缀 mod_owp_provision_。
 *
 * 不确定 Capsule schema 行为时，先在 addon `_activate()` 里 try/catch 落日志。
 *
 * @package OwpProvision
 * @target  WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Schema
{
    /** 当前 schema 版本；addon `_upgrade()` 按此迁移。 */
    public const VERSION = '1.0.0';

    public const T_POOLS       = 'mod_owp_provision_pools';       // 已弃用（迁移源/回滚保留）
    public const T_RESOURCES   = 'mod_owp_provision_resources';   // 清单式 IPAM：逐条具体资源
    public const T_ALLOCATIONS = 'mod_owp_provision_allocations';
    public const T_CONFIG      = 'mod_owp_provision_config';
    public const T_LOG         = 'mod_owp_provision_log';
    public const T_DEVICES     = 'mod_owp_provision_devices';
    public const T_OPLOG       = 'mod_owp_provision_oplog';       // v2 编排器按步日志（保留 7 天）
    public const T_SERVERS     = 'mod_owp_provision_servers';     // v2 服务器库存（租赁/托管）

    /**
     * 幂等建全部表。返回简单结果数组，便于 addon `_activate()` 直接转成 WHMCS 期望格式。
     *
     * @return array{status:string, description:string}
     */
    public static function install(): array
    {
        try {
            self::createDevices();
            self::createPools();
            self::createResources();
            self::createAllocations();
            self::createConfig();
            self::createLog();
            self::createOplog();
            self::createServers();

            return [
                'status'      => 'success',
                'description' => 'OWP Provision：8 张表已就绪（devices/pools/resources/allocations/config/log/oplog/servers）。',
            ];
        } catch (\Throwable $e) {
            return [
                'status'      => 'error',
                'description' => 'IP-Delivery 建表失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * server 模块兜底：确保表存在（静默，失败抛异常给调用方的 try/catch）。
     */
    public static function ensureTables(): void
    {
        self::createDevices();
        self::createPools();
        self::createResources();
        self::createAllocations();
        self::createConfig();
        self::createLog();
        self::createOplog();
        self::createServers();
        self::autoSeedResources(); // 安全网：升级后即使 _upgrade 未触发，也保证 resources 已从 pools 播种一次
    }

    /**
     * 一次性把母段式 pools 播种成 resources（仅当从未迁移过）。用 T_CONFIG 标记 `resources_migrated`
     * 守卫——管理员日后清空 resources 也不会被旧 pools 重新「复活」。
     */
    private static function autoSeedResources(): void
    {
        try {
            if (self::resourcesMigrated()) {
                return;
            }
            if (Capsule::schema()->hasTable(self::T_POOLS) && (int) Capsule::table(self::T_POOLS)->count() > 0) {
                self::migrateToResources();
            }
            self::markResourcesMigrated();
        } catch (\Throwable $e) {
            // 播种失败不阻断建表；_upgrade 路径仍会再尝试。
        }
    }

    private static function resourcesMigrated(): bool
    {
        try {
            return (int) Capsule::table(self::T_CONFIG)->where('setting', 'resources_migrated')->count() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function markResourcesMigrated(): void
    {
        try {
            if (!self::resourcesMigrated()) {
                Capsule::table(self::T_CONFIG)->insert([
                    'setting' => 'resources_migrated', 'value' => '1', 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 可分配资源池。kind: vlan / ptp / prefix / port / loopback。
     */
    public static function createPools(): void
    {
        if (Capsule::schema()->hasTable(self::T_POOLS)) {
            return;
        }
        Capsule::schema()->create(self::T_POOLS, function ($t) {
            $t->increments('id');
            $t->unsignedInteger('device_id')->default(1)->comment('所属设备 mod_owp_provision_devices.id');
            // enum 用 string 存，避免不同 MySQL 版本 enum 迁移坑；应用层校验取值。
            $t->string('kind', 16)->comment('vlan|ptp|prefix|port|loopback|tunnel|acl');
            $t->string('value', 255)->comment('池定义');
            $t->text('meta')->nullable()->comment('JSON：掩码/允许范围/备注');
            $t->tinyInteger('enabled')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->index('kind');
            $t->index('device_id');
            $t->engine = 'InnoDB';
        });
    }

    /**
     * 清单式 IPAM：每条具体资源一行（端口/PTP 子段/Loopback/Prefix/VLAN/Tunnel/ACL）。
     * 占用**不存这里**，由 allocations 实时算（删掉旧的 meta.exclude 手工排除）。
     * value：整数(vlan/tunnel/acl) | 网络地址或 IP(ptp/prefix/loopback) | 端口名(port)。
     * mask：ptp/prefix/loopback 的前缀长度；vlan/tunnel/acl/port 为 NULL。
     * source：auto（母段切分/迁移）| manual（手动逐条）。note：如 forced（强制保存留痕）。
     */
    public static function createResources(): void
    {
        if (Capsule::schema()->hasTable(self::T_RESOURCES)) {
            return;
        }
        Capsule::schema()->create(self::T_RESOURCES, function ($t) {
            $t->increments('id');
            $t->unsignedInteger('device_id')->default(1)->comment('所属设备 mod_owp_provision_devices.id');
            $t->string('kind', 16)->comment('vlan|ptp|prefix|port|loopback|tunnel|acl');
            $t->string('value', 64)->comment('整数/网络地址/IP/端口名');
            $t->unsignedInteger('mask')->nullable()->comment('ptp/prefix/loopback 前缀长度；其余 NULL');
            $t->string('source', 8)->default('auto')->comment('auto|manual');
            $t->tinyInteger('enabled')->default(1);
            $t->string('note', 128)->nullable()->comment('如 forced（强制保存留痕）');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->index('device_id');
            $t->index('kind');
            $t->engine = 'InnoDB';
        });
    }

    /**
     * 已分配明细。serviceid 唯一（一个服务一条）。
     */
    public static function createAllocations(): void
    {
        if (Capsule::schema()->hasTable(self::T_ALLOCATIONS)) {
            return;
        }
        Capsule::schema()->create(self::T_ALLOCATIONS, function ($t) {
            $t->increments('id');
            $t->unsignedInteger('serviceid')->comment('tblhosting.id');
            $t->unsignedInteger('device_id')->default(1)->comment('所属设备 mod_owp_provision_devices.id');
            $t->string('delivery_type', 8)->comment('xc|gre');
            $t->unsignedInteger('vlan_id')->nullable()->comment('XC 用');
            $t->string('ptp_net', 32)->nullable()->comment('PTP /30，如 100.64.0.8/30');
            $t->string('ptp_our', 32)->nullable()->comment('我方地址（.X+1）');
            $t->string('ptp_peer', 32)->nullable()->comment('客户侧（.X+2）');
            $t->string('prefix', 48)->nullable()->comment('交付段，如 203.0.113.240/28');
            $t->string('port', 48)->nullable()->comment('XC 物理口');
            $t->unsignedInteger('tunnel_id')->nullable()->comment('GRE Tunnel/LoopBack 号（共用同号）');
            $t->string('loopback_ip', 32)->nullable()->comment('GRE 源 /32');
            $t->string('tunnel_source', 32)->nullable()->comment('= loopback_ip，冗余便于展示');
            $t->unsignedInteger('acl_id')->nullable()->comment('GRE 限速高级 ACL 号');
            $t->string('remote_ip', 32)->nullable()->comment('GRE 客户对端（客户区可改）');
            $t->string('bandwidth', 16)->nullable()->comment('限速档，如 100M');
            $t->string('policy_name', 64)->nullable()->comment('traffic-policy 名，tp-{serviceid}');
            $t->string('status', 16)->default('active')->comment('active|suspended|terminated');
            $t->timestamp('remote_changed_at')->nullable()->comment('GRE 改对端限频用');
            // v2 VPN（RouterOS）：每服务的 VPN 接入（IPMI 等）。vpn_device_id 可与交付设备不同（ROS 单独一台）。
            $t->unsignedInteger('vpn_device_id')->nullable()->comment('VPN 所在 ROS 设备 id');
            $t->string('vpn_ip', 32)->nullable()->comment('分给客户的 VPN 固定地址（/32）');
            $t->string('vpn_target', 64)->nullable()->comment('该 VPN 可达的目标（如其 IPMI IP）');
            $t->string('vpn_user', 64)->nullable()->comment('VPN 账号名');
            $t->text('vpn_pass_enc')->nullable()->comment('VPN 密码（EncryptPassword 加密）');
            $t->tinyInteger('vpn_revealed')->default(0)->comment('VPN 凭据是否已被客户查看过（一次性）');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique('serviceid');
            $t->index('status');
            $t->engine = 'InnoDB';
        });
    }

    /**
     * 设备表：每台接入交换机一条连接配置（非敏感）。敏感凭据加密存 mod_owp_provision_config，
     * key 带设备前缀 `dev{id}_writePass` 等（见 Config）。
     */
    public static function createDevices(): void
    {
        if (Capsule::schema()->hasTable(self::T_DEVICES)) {
            return;
        }
        Capsule::schema()->create(self::T_DEVICES, function ($t) {
            $t->increments('id');
            $t->string('name', 64)->comment('设备显示名，如 Edge-A');
            $t->string('driver', 8)->default('vrp')->comment('设备类型驱动：vrp|ros|drac');
            $t->tinyInteger('enabled')->default(1);
            $t->string('conn_mode', 8)->default('jump')->comment('direct|jump');
            $t->string('device_host', 128)->nullable();
            $t->string('device_port', 8)->default('22');
            $t->string('write_user', 32)->nullable();
            $t->string('read_user', 32)->nullable();
            $t->string('kex', 128)->nullable();
            $t->string('jump_host', 128)->nullable();
            $t->string('jump_port', 8)->default('22');
            $t->string('jump_user', 32)->nullable();
            $t->string('jump_key_path', 255)->nullable();
            $t->string('timeout', 6)->default('30');
            // ROS（driver=ros）站点字段：白标，后台填。IPsec PSK 走加密 deviceSecret('ros_ipsec_psk')。
            $t->string('ros_lan_if', 32)->nullable()->comment('ROS 内网(IPMI侧)接口名，如 lan-edge');
            $t->string('ros_wan_if', 32)->nullable()->comment('ROS 公网接口名，如 wan-uplink');
            $t->string('ros_l2tp_local', 32)->nullable()->comment('VPN 本端地址，如 10.0.0.254');
            $t->string('ros_ikev2_peer', 64)->nullable()->comment('全局 IKEv2 peer 名（一次性预置）；空=不开 IKEv2');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->engine = 'InnoDB';
        });
    }

    /**
     * 连接配置（单行）。可与 addon `_config` 字段二选一；这里建表是为「server 也能独立读」
     * 的备选。默认实现优先从 tbladdonmodules 读 addon 配置（一处管理），此表留作可选覆盖/迁移。
     */
    public static function createConfig(): void
    {
        if (Capsule::schema()->hasTable(self::T_CONFIG)) {
            return;
        }
        Capsule::schema()->create(self::T_CONFIG, function ($t) {
            $t->increments('id');
            $t->string('setting', 64)->unique();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->engine = 'InnoDB';
        });
    }

    /**
     * 下发审计日志（可选；logModuleCall 之外的结构化留痕）。
     */
    public static function createLog(): void
    {
        if (Capsule::schema()->hasTable(self::T_LOG)) {
            return;
        }
        Capsule::schema()->create(self::T_LOG, function ($t) {
            $t->increments('id');
            $t->unsignedInteger('serviceid')->nullable()->index();
            $t->string('action', 32)->comment('CreateAccount/Suspend/...');
            $t->longText('command_block')->nullable()->comment('下发/将下发的命令块');
            $t->longText('device_output')->nullable()->comment('设备回显（去密钥）');
            $t->string('result', 16)->default('')->comment('success|error|dryrun');
            $t->timestamp('created_at')->nullable();
            $t->engine = 'InnoDB';
        });
    }

    /**
     * v2 编排器「按步日志」专表：一单一步一行（phase=create/terminate/...；status=ok/failed/...）。
     * 供后台「开通队列 + 步骤时间线」展示「卡在哪一步」；保留 7 天（Orchestrator::purgeOplog）。
     */
    public static function createOplog(): void
    {
        if (Capsule::schema()->hasTable(self::T_OPLOG)) {
            return;
        }
        Capsule::schema()->create(self::T_OPLOG, function ($t) {
            $t->increments('id');
            $t->unsignedInteger('serviceid')->nullable()->index();
            $t->unsignedInteger('device_id')->nullable()->comment('涉及的设备（无则 NULL）');
            $t->string('phase', 24)->default('')->comment('create|terminate|suspend|unsuspend|change|test');
            $t->string('step', 64)->comment('步骤名，如 allocate / vrp.provision / ros.vpn');
            $t->string('status', 12)->default('')->comment('ok|failed|skipped|rollback|rollback_failed|dryrun');
            $t->text('request')->nullable()->comment('该步请求/命令摘要');
            $t->longText('response')->nullable()->comment('该步回显/错误');
            $t->timestamp('created_at')->nullable()->index();
            $t->engine = 'InnoDB';
        });
    }

    /**
     * v2 服务器库存（租赁/托管）：每台物理机一行，admin 维护。下单选服务器→绑它的交换机端口 +
     * 经其 ROS 开 IPMI VPN。白标：IP/接口等都在设备/资源里配，这里只是资产登记。
     */
    public static function createServers(): void
    {
        if (Capsule::schema()->hasTable(self::T_SERVERS)) {
            return;
        }
        Capsule::schema()->create(self::T_SERVERS, function ($t) {
            $t->increments('id');
            $t->string('name', 64)->comment('资产名/标签，如 R640-01');
            $t->unsignedInteger('device_id')->comment('线缆所在交换机 mod_owp_provision_devices.id');
            $t->string('port', 48)->comment('服务器 NIC 线缆到的交换机端口');
            $t->unsignedInteger('vpn_device_id')->nullable()->comment('其 IPMI 所在 ROS 设备 id（开 VPN 用）');
            $t->string('ipmi_ip', 32)->nullable()->comment('IPMI/BMC 地址');
            $t->string('ipmi_kind', 12)->default('idrac')->comment('idrac|ilo|generic');
            $t->string('line', 64)->nullable()->comment('该服务器可用线路标签（对应 prefix 资源 line，可空=不限）');
            $t->text('specs')->nullable()->comment('规格描述（CPU/内存/盘/网卡）');
            $t->string('status', 12)->default('free')->comment('free|rented|maintenance');
            $t->unsignedInteger('serviceid')->nullable()->comment('当前租用的服务 id');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->index('device_id');
            $t->index('status');
            $t->engine = 'InnoDB';
        });
    }

    /**
     * 按版本迁移。1.0.0 为初始版本，暂无迁移步骤；后续在此 switch。
     *
     * @param string $fromVersion 已安装版本（addon `_upgrade` 传入 $vars['version']）
     */
    public static function migrate(string $fromVersion): void
    {
        // 兜底建表（旧装机可能缺表）。
        self::ensureTables();

        // 1.1.0：allocations 增加 acl_id（GRE 限速高级 ACL 号）。已装库（缺该列）在此补上。
        if (version_compare($fromVersion, '1.1.0', '<')) {
            if (Capsule::schema()->hasTable(self::T_ALLOCATIONS)
                && !Capsule::schema()->hasColumn(self::T_ALLOCATIONS, 'acl_id')) {
                Capsule::schema()->table(self::T_ALLOCATIONS, function ($t) {
                    $t->unsignedInteger('acl_id')->nullable()->after('tunnel_source');
                });
            }
        }

        // 1.2.0：多设备——pools/allocations 加 device_id；旧全局连接配置/凭据迁成「设备 1」。
        if (version_compare($fromVersion, '1.2.0', '<')) {
            self::migrateToMultiDevice();
        }

        // 1.3.0：清单式 IPAM——把母段式 pools 展开成具体资源条目入 resources（honor meta.exclude）。
        if (version_compare($fromVersion, '1.3.0', '<')) {
            self::migrateToResources();
            self::markResourcesMigrated();
        }
    }

    /** 1.2.0 多设备迁移（幂等）：补 device_id 列、用旧全局配置/凭据建「设备 1」、池/分配归 1。 */
    private static function migrateToMultiDevice(): void
    {
        // a) pools/allocations 补 device_id 列（默认 1）
        if (Capsule::schema()->hasTable(self::T_POOLS) && !Capsule::schema()->hasColumn(self::T_POOLS, 'device_id')) {
            Capsule::schema()->table(self::T_POOLS, function ($t) {
                $t->unsignedInteger('device_id')->default(1)->after('id');
            });
        }
        if (Capsule::schema()->hasTable(self::T_ALLOCATIONS) && !Capsule::schema()->hasColumn(self::T_ALLOCATIONS, 'device_id')) {
            Capsule::schema()->table(self::T_ALLOCATIONS, function ($t) {
                $t->unsignedInteger('device_id')->default(1)->after('serviceid');
            });
        }
        // b) 若无任何设备 → 用旧全局连接配置建「设备 1」
        if ((int) Capsule::table(self::T_DEVICES)->count() === 0) {
            $g = [];
            foreach (Capsule::table('tbladdonmodules')->where('module', 'owp_provision')->get() as $r) {
                $g[(string) $r->setting] = (string) ($r->value ?? '');
            }
            Capsule::table(self::T_DEVICES)->insert([
                'id'            => 1,
                'name'          => 'Default',
                'enabled'       => 1,
                'conn_mode'     => $g['connMode'] ?? 'jump',
                'device_host'   => $g['deviceHost'] ?? '',
                'device_port'   => $g['devicePort'] ?? '22',
                'write_user'    => $g['writeUser'] ?? '',
                'read_user'     => $g['readUser'] ?? '',
                'kex'           => $g['kex'] ?? '',
                'jump_host'     => $g['jumpHost'] ?? '',
                'jump_port'     => $g['jumpPort'] ?? '22',
                'jump_user'     => $g['jumpUser'] ?? 'root',
                'jump_key_path' => $g['jumpKeyPath'] ?? '',
                'timeout'       => $g['timeout'] ?? '30',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            // c) 旧全局加密凭据（mod_owp_provision_config）改 dev1_ 前缀（加密 blob 不变，只改 setting 名）
            foreach (['writePass', 'readPass', 'jumpPass', 'jumpKeyPassphrase', 'jumpKeyText'] as $k) {
                $has    = Capsule::table(self::T_CONFIG)->where('setting', $k)->exists();
                $hasNew = Capsule::table(self::T_CONFIG)->where('setting', 'dev1_' . $k)->exists();
                if ($has && !$hasNew) {
                    Capsule::table(self::T_CONFIG)->where('setting', $k)->update(['setting' => 'dev1_' . $k]);
                }
            }
        }
        // d) 兜底：device_id 为空/0 的池与分配归「设备 1」
        try { Capsule::table(self::T_POOLS)->whereNull('device_id')->orWhere('device_id', 0)->update(['device_id' => 1]); } catch (\Throwable $e) {}
        try { Capsule::table(self::T_ALLOCATIONS)->whereNull('device_id')->orWhere('device_id', 0)->update(['device_id' => 1]); } catch (\Throwable $e) {}
    }

    /**
     * 1.3.0 清单式 IPAM 迁移（幂等）：把母段式 pools 展开成具体 resources 条目（source=auto），
     * honor 原 meta.exclude（被排除值不入清单），并为在用标量分配补条目（可见为「占用」）。
     * 切分规则沿用旧分配器：ptp→/30、loopback→/32、prefix→deliver_min（无则母段自身一条）；
     * vlan/tunnel/acl→整数；port→列表。可分配集合与迁移前一致（ptp/prefix 用 CIDR 重叠判占用）。
     * 复用 Ipam 的 CIDR 工具（migrate 由 addon _upgrade 调用时 Ipam 已 require）。
     */
    private static function migrateToResources(): void
    {
        self::createResources();
        if ((int) Capsule::table(self::T_RESOURCES)->count() > 0) {
            return; // 已迁移，幂等跳过
        }
        if (!Capsule::schema()->hasTable(self::T_POOLS)) {
            return;
        }
        $now  = date('Y-m-d H:i:s');
        $rows = [];
        $seen = [];
        $add = function (int $devId, string $kind, string $value, ?int $mask) use (&$rows, &$seen, $now) {
            $key = $devId . '|' . $kind . '|' . $value . '|' . ($mask ?? '');
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $rows[] = [
                'device_id' => $devId, 'kind' => $kind, 'value' => $value, 'mask' => $mask,
                'source' => 'auto', 'enabled' => 1, 'note' => 'migrated',
                'created_at' => $now, 'updated_at' => $now,
            ];
        };
        $netOf = static function (string $cidr): string { return explode('/', $cidr)[0]; };
        $overlapsExclude = static function (string $net, int $len, array $exclude): bool {
            foreach ($exclude as $ex) {
                if ($ex === '' || $ex === null) {
                    continue;
                }
                try {
                    if (Ipam::cidrOverlap($net . '/' . $len, (string) $ex)) {
                        return true;
                    }
                } catch (\Throwable $e) {
                }
            }
            return false;
        };

        foreach (Capsule::table(self::T_POOLS)->get() as $p) {
            $devId = (int) ($p->device_id ?? 1);
            if ($devId <= 0) {
                $devId = 1;
            }
            $kind    = (string) $p->kind;
            $value   = (string) $p->value;
            $meta    = Ipam::decodeMeta($p->meta);
            $exclude = isset($meta['exclude']) && is_array($meta['exclude']) ? $meta['exclude'] : [];

            try {
                switch ($kind) {
                    case 'vlan':
                    case 'tunnel':
                    case 'acl':
                        $exInts = array_map('intval', $exclude);
                        foreach (Ipam::expandVlanRange($value) as $n) {
                            if (in_array($n, $exInts, true)) {
                                continue;
                            }
                            $add($devId, $kind, (string) $n, null);
                        }
                        break;
                    case 'ptp':
                        foreach (Ipam::splitCidr($value, 30) as $cidr) {
                            $net = $netOf($cidr);
                            if ($overlapsExclude($net, 30, $exclude)) {
                                continue;
                            }
                            $add($devId, 'ptp', $net, 30);
                        }
                        break;
                    case 'loopback':
                        foreach (Ipam::splitCidr($value, 32) as $cidr) {
                            $ip = $netOf($cidr);
                            if ($overlapsExclude($ip, 32, $exclude)) {
                                continue;
                            }
                            $add($devId, 'loopback', $ip, 32);
                        }
                        break;
                    case 'prefix':
                        $poolLen = (int) Ipam::parseCidr($value)['len'];
                        $carve   = isset($meta['deliver_min']) ? (int) $meta['deliver_min'] : $poolLen;
                        if ($carve < $poolLen) {
                            $carve = $poolLen;
                        }
                        if ($carve > 32) {
                            $carve = 32;
                        }
                        foreach (Ipam::splitCidr($value, $carve) as $cidr) {
                            $net = $netOf($cidr);
                            if ($overlapsExclude($net, $carve, $exclude)) {
                                continue;
                            }
                            $add($devId, 'prefix', $net, $carve);
                        }
                        break;
                    case 'port':
                        foreach (array_map('trim', explode(',', $value)) as $port) {
                            if ($port === '' || in_array($port, $exclude, true)) {
                                continue;
                            }
                            $add($devId, 'port', $port, null);
                        }
                        break;
                }
            } catch (\Throwable $e) {
                // 单个脏池跳过，不阻断整体迁移。
            }
        }

        // 标量类：为在用（非 terminated）分配补条目，保证「占用」可见、不丢资源（ptp/prefix 靠重叠判占）。
        try {
            foreach (Capsule::table(self::T_ALLOCATIONS)->where('status', '!=', 'terminated')->get() as $a) {
                $devId = (int) ($a->device_id ?? 1);
                if ($devId <= 0) {
                    $devId = 1;
                }
                if (!empty($a->vlan_id)) {
                    $add($devId, 'vlan', (string) (int) $a->vlan_id, null);
                }
                if (!empty($a->tunnel_id)) {
                    $add($devId, 'tunnel', (string) (int) $a->tunnel_id, null);
                }
                if (!empty($a->acl_id)) {
                    $add($devId, 'acl', (string) (int) $a->acl_id, null);
                }
                if (!empty($a->port)) {
                    $add($devId, 'port', (string) $a->port, null);
                }
                if (!empty($a->loopback_ip)) {
                    $add($devId, 'loopback', (string) $a->loopback_ip, 32);
                }
            }
        } catch (\Throwable $e) {
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Capsule::table(self::T_RESOURCES)->insert($chunk);
        }
    }

    /**
     * 危险：删全部表。默认不在任何生命周期里调用；仅供手动清理脚本/测试。
     * addon `_deactivate()` 故意不调用它，避免误删占用记录。
     */
    public static function dropAll(): void
    {
        foreach ([self::T_SERVERS, self::T_OPLOG, self::T_LOG, self::T_ALLOCATIONS, self::T_CONFIG, self::T_RESOURCES, self::T_POOLS, self::T_DEVICES] as $tbl) {
            if (Capsule::schema()->hasTable($tbl)) {
                Capsule::schema()->drop($tbl);
            }
        }
    }
}
