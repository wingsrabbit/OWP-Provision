<?php
/**
 * OWP Provision — Orchestrator.php  (v2 编排器)
 * ----------------------------------------------------------------------------
 * 产品蓝图 = 一串「步骤」。编排器负责：
 *   - **全局锁串行**：一次只跑一单开通/拆除，其余排队等待（杜绝并发乱序——iDRAC/ROS
 *     并发最易出问题）。用 MySQL `GET_LOCK` 实现（无需自建队列表）。
 *   - **按步日志**：每步成功/失败写 `mod_owp_provision_oplog`（phase/step/device/status/
 *     request/response），供后台「开通队列 + 步骤时间线」看「卡在哪一步」；保留 7 天。
 *   - **失败逐步回滚**：某步抛错 → 逆序调用已成功步骤的 `undo`，尽力恢复，全程留痕。
 *
 * 一个 step（关联数组）：
 *   [ 'name' => 'vrp.provision', 'device_id' => 3,
 *     'do'   => function (array $ctx): array|string { ...; return $detailForLog; },
 *     'undo' => function (array $ctx): void { ... },   // 可选；回滚用
 *     'request' => '可选：命令/请求摘要（落日志）' ]
 * `do` 抛异常即视为该步失败。`do`/`undo` 收到运行上下文 $ctx（步骤间可经返回值累积，见 run）。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Orchestrator
{
    private const LOCK_PREFIX  = 'owp_provision_';
    private const LOCK_TIMEOUT = 120; // 秒：等锁上限（前一单未完成则在此排队）
    private const LOG_RETAIN   = 7;   // 天：oplog 保留期

    /**
     * 取全局串行锁跑 $fn；拿不到锁（另一单在跑且超时）→ 抛错让本次失败、稍后重试。
     * @template T
     * @param callable():mixed $fn
     * @return mixed
     */
    public static function withLock(callable $fn, string $scope = 'global')
    {
        $lock = self::LOCK_PREFIX . $scope;
        try {
            $row = Capsule::selectOne('SELECT GET_LOCK(?, ?) AS g', [$lock, self::LOCK_TIMEOUT]);
        } catch (\Throwable $e) {
            // 拿锁机制本身异常（极少）：降级为不加锁执行，避免完全卡死。
            return $fn();
        }
        if (!$row || (int) ($row->g ?? 0) !== 1) {
            throw new \RuntimeException('系统繁忙：另一开通/拆除任务进行中，请稍后重试（编排器串行执行）。');
        }
        try {
            return $fn();
        } finally {
            try {
                Capsule::select('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * 顺序执行步骤；每步落日志；任一步失败 → 逆序回滚已成功步骤。
     * 步骤间共享 $ctx：每个 `do` 的返回值（数组）会 merge 进 $ctx 供后续步骤读取。
     *
     * @param array<int,array<string,mixed>> $steps
     * @return array{ok:bool, failedStep:?string, error:?string, ctx:array}
     */
    public static function run(int $serviceId, string $phase, array $steps, array $ctx = []): array
    {
        self::purgeOplog();
        $done = [];
        foreach ($steps as $i => $step) {
            $name  = (string) ($step['name'] ?? ('step' . $i));
            $devId = isset($step['device_id']) ? (int) $step['device_id'] : null;
            $req   = (string) ($step['request'] ?? '');
            if (empty($step['do']) || !is_callable($step['do'])) {
                continue;
            }
            try {
                $detail = ($step['do'])($ctx);
                if (is_array($detail)) {
                    $ctx = array_merge($ctx, $detail);
                    $resp = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $resp = (string) $detail;
                }
                self::log($serviceId, $phase, $name, $devId, 'ok', $req, (string) $resp);
                $done[] = ['step' => $step, 'name' => $name, 'device_id' => $devId];
            } catch (\Throwable $e) {
                self::log($serviceId, $phase, $name, $devId, 'failed', $req, $e->getMessage());
                self::rollback($serviceId, $phase, $done, $ctx);
                return ['ok' => false, 'failedStep' => $name, 'error' => $e->getMessage(), 'ctx' => $ctx];
            }
        }
        return ['ok' => true, 'failedStep' => null, 'error' => null, 'ctx' => $ctx];
    }

    /** 逆序回滚已成功步骤的 undo（best-effort，全程留痕；undo 自身报错不再上抛）。 */
    private static function rollback(int $serviceId, string $phase, array $done, array $ctx): void
    {
        foreach (array_reverse($done) as $d) {
            $step = $d['step'];
            if (empty($step['undo']) || !is_callable($step['undo'])) {
                continue;
            }
            try {
                ($step['undo'])($ctx);
                self::log($serviceId, $phase, $d['name'] . ':rollback', $d['device_id'], 'rollback', '', '');
            } catch (\Throwable $e) {
                self::log($serviceId, $phase, $d['name'] . ':rollback', $d['device_id'], 'rollback_failed', '', $e->getMessage());
            }
        }
    }

    /** 写一条按步日志（容错：日志失败不影响主流程）。 */
    public static function log(int $serviceId, string $phase, string $step, ?int $deviceId, string $status, string $request = '', string $response = ''): void
    {
        try {
            Capsule::table(Schema::T_OPLOG)->insert([
                'serviceid'  => $serviceId ?: null,
                'device_id'  => $deviceId ?: null,
                'phase'      => mb_substr($phase, 0, 24),
                'step'       => mb_substr($step, 0, 64),
                'status'     => mb_substr($status, 0, 12),
                'request'    => $request !== '' ? mb_substr($request, 0, 8000) : null,
                'response'   => $response !== '' ? mb_substr($response, 0, 60000) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** 删除 7 天前的按步日志（每次 run 触发一次，低频足够）。 */
    public static function purgeOplog(): void
    {
        try {
            Capsule::table(Schema::T_OPLOG)
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::LOG_RETAIN * 86400))
                ->delete();
        } catch (\Throwable $e) {
        }
    }

    /** 取某服务最近的按步日志（后台时间线用）。 */
    public static function stepsFor(int $serviceId, int $limit = 200): array
    {
        try {
            return Capsule::table(Schema::T_OPLOG)->where('serviceid', $serviceId)
                ->orderByDesc('id')->limit($limit)->get()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
