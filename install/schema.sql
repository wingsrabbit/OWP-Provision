-- ============================================================================
-- IP-Delivery — 等效建表 SQL（参考 / 手动用）
-- ----------------------------------------------------------------------------
-- 正常安装时由 addon `_activate()`（lib/Schema.php，走 WHMCS Capsule）自动建表，
-- 无需手动跑此文件。此处提供等效 SQL 便于审计 / 手动初始化 / 排错。
--
-- 引擎 InnoDB，字符集 utf8mb4。表前缀 mod_owp_provision_。
-- 与 lib/Schema.php 的列定义保持一致（如有出入以 Schema.php 为准）。
--
-- ⚠ 不含任何凭据/密钥；连接配置存在 WHMCS 的 tbladdonmodules（addon 加密字段）。
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 0) 设备（每台接入交换机一条；非敏感连接配置。敏感凭据加密存 _config 的 dev{id}_*）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_owp_provision_devices` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(64)  NOT NULL COMMENT '设备显示名，如 Edge-A',
  `enabled`       TINYINT      NOT NULL DEFAULT 1,
  `conn_mode`     VARCHAR(8)   NOT NULL DEFAULT 'jump' COMMENT 'direct|jump',
  `device_host`   VARCHAR(128) NULL,
  `device_port`   VARCHAR(8)   NOT NULL DEFAULT '22',
  `write_user`    VARCHAR(32)  NULL,
  `read_user`     VARCHAR(32)  NULL,
  `kex`           VARCHAR(128) NULL,
  `jump_host`     VARCHAR(128) NULL,
  `jump_port`     VARCHAR(8)   NOT NULL DEFAULT '22',
  `jump_user`     VARCHAR(32)  NULL,
  `jump_key_path` VARCHAR(255) NULL,
  `timeout`       VARCHAR(6)   NOT NULL DEFAULT '30',
  `created_at`    TIMESTAMP    NULL,
  `updated_at`    TIMESTAMP    NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) 资源池（母段式；**弃用**，仅作迁移源/回滚保留。新逻辑用 _resources）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_owp_provision_pools` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '所属设备 mod_owp_provision_devices.id',
  `kind`       VARCHAR(16)  NOT NULL COMMENT 'vlan|ptp|prefix|port|loopback|tunnel|acl',
  `value`      VARCHAR(255) NOT NULL COMMENT '池定义（母段/范围）',
  `meta`       TEXT         NULL     COMMENT 'JSON：掩码/允许范围/exclude/备注（已弃用）',
  `enabled`    TINYINT      NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NULL,
  `updated_at` TIMESTAMP    NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kind` (`kind`),
  KEY `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 1b) 资源清单（清单式 IPAM：每条具体资源一行；占用不落库，由 _allocations 实时算）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_owp_provision_resources` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '所属设备 mod_owp_provision_devices.id',
  `kind`       VARCHAR(16)  NOT NULL COMMENT 'vlan|ptp|prefix|port|loopback|tunnel|acl',
  `value`      VARCHAR(64)  NOT NULL COMMENT '整数/网络地址/IP/端口名',
  `mask`       INT UNSIGNED NULL     COMMENT 'ptp/prefix/loopback 前缀长度；其余 NULL',
  `source`     VARCHAR(8)   NOT NULL DEFAULT 'auto' COMMENT 'auto|manual',
  `enabled`    TINYINT      NOT NULL DEFAULT 1,
  `note`       VARCHAR(128) NULL     COMMENT '如 forced（强制保存留痕）',
  `created_at` TIMESTAMP    NULL,
  `updated_at` TIMESTAMP    NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device` (`device_id`),
  KEY `idx_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 2) 已分配明细（serviceid 唯一）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_owp_provision_allocations` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `serviceid`         INT UNSIGNED NOT NULL COMMENT 'tblhosting.id',
  `device_id`         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '所属设备 mod_owp_provision_devices.id',
  `delivery_type`     VARCHAR(8)   NOT NULL COMMENT 'xc|gre',
  `vlan_id`           INT UNSIGNED NULL     COMMENT 'XC 用',
  `ptp_net`           VARCHAR(32)  NULL     COMMENT 'PTP /30',
  `ptp_our`           VARCHAR(32)  NULL     COMMENT '我方 .X+1',
  `ptp_peer`          VARCHAR(32)  NULL     COMMENT '客户侧 .X+2',
  `prefix`            VARCHAR(48)  NULL     COMMENT '交付段 a.b.c.d/NN',
  `port`              VARCHAR(48)  NULL     COMMENT 'XC 物理口',
  `tunnel_id`         INT UNSIGNED NULL     COMMENT 'GRE Tunnel/LoopBack 号',
  `loopback_ip`       VARCHAR(32)  NULL     COMMENT 'GRE 源 /32',
  `tunnel_source`     VARCHAR(32)  NULL     COMMENT '= loopback_ip',
  `acl_id`            INT UNSIGNED NULL     COMMENT 'GRE 限速高级 ACL 号',
  `remote_ip`         VARCHAR(32)  NULL     COMMENT 'GRE 客户对端（客户区可改）',
  `bandwidth`         VARCHAR(16)  NULL     COMMENT '限速档 100M 等',
  `policy_name`       VARCHAR(64)  NULL     COMMENT 'traffic-policy 名 tp-{serviceid}',
  `status`            VARCHAR(16)  NOT NULL DEFAULT 'active' COMMENT 'active|suspended|terminated',
  `remote_changed_at` TIMESTAMP    NULL     COMMENT 'GRE 改对端限频',
  `created_at`        TIMESTAMP    NULL,
  `updated_at`        TIMESTAMP    NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_serviceid` (`serviceid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 3) 连接配置（可选；默认连接配置走 tbladdonmodules，此表留作覆盖/迁移备选）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_owp_provision_config` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting`    VARCHAR(64)  NOT NULL,
  `value`      TEXT         NULL,
  `updated_at` TIMESTAMP    NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting` (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4) 下发审计日志（可选；logModuleCall 之外的结构化留痕）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mod_owp_provision_log` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `serviceid`     INT UNSIGNED NULL,
  `action`        VARCHAR(32)  NOT NULL,
  `command_block` LONGTEXT     NULL COMMENT '下发/将下发的命令块',
  `device_output` LONGTEXT     NULL COMMENT '设备回显（去密钥）',
  `result`        VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'success|error|dryrun',
  `created_at`    TIMESTAMP    NULL,
  PRIMARY KEY (`id`),
  KEY `idx_serviceid` (`serviceid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 可选：初始化资源池（按你的真实可交付聚合/端口调整后再执行）
-- ============================================================================
-- INSERT INTO `mod_owp_provision_pools` (`kind`,`value`,`meta`,`enabled`,`created_at`,`updated_at`) VALUES
--   ('vlan',     '1000-1100',                          NULL,                                   1, NOW(), NOW()),
--   ('ptp',      '100.64.0.0/24',                    '{"split":30}',                          1, NOW(), NOW()),
--   ('prefix',   '203.0.113.0/24',                     '{"deliver_min":28,"deliver_max":32}',   1, NOW(), NOW()),
--   ('port',     'XGE0/0/1,XGE0/0/2,XGE0/0/3,XGE0/0/4,XGE0/0/10,XGE0/0/11,XGE0/0/20,XGE0/0/21,XGE0/0/22,XGE0/0/23', NULL, 1, NOW(), NOW()),
--   ('loopback', '198.51.100.0/28',                  '{"exclude":["198.51.100.9/32","198.51.100.9/32"]}', 1, NOW(), NOW()),
--   ('tunnel',   '1000-1999',                          NULL,                                   1, NOW(), NOW());

-- ============================================================================
-- 卸载（谨慎！会删占用记录）
-- ============================================================================
-- DROP TABLE IF EXISTS `mod_owp_provision_log`;
-- DROP TABLE IF EXISTS `mod_owp_provision_allocations`;
-- DROP TABLE IF EXISTS `mod_owp_provision_config`;
-- DROP TABLE IF EXISTS `mod_owp_provision_resources`;
-- DROP TABLE IF EXISTS `mod_owp_provision_pools`;
-- DROP TABLE IF EXISTS `mod_owp_provision_devices`;
