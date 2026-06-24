<?php
/**
 * OWP Provision — lib/Jobs.php  (v2.8 · 异步开通队列)
 * ----------------------------------------------------------------------------
 * CreateAccount 入队即返回（结账/下单不卡），cron(AfterCronJob) 逐单跑真机编排。
 * 一服务一行（serviceid 唯一）；payload = 加密序列化的 $params，worker 直接重入
 * owp_provision_CreateAccount($params)（带全局标志）跑真活——不经 localAPI/ModuleCreate
 * （那会重发欢迎邮件）、也无需重建 params。
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class Jobs
{
    /** 入队（幂等：已存在则回到 queued、刷新 payload）。$params 加密序列化存 payload。 */
    public static function enqueue(int $serviceId, string $type, array $params): void
    {
        $now     = date('Y-m-d H:i:s');
        $payload = Config::encrypt(serialize(self::trimParams($params)));
        $row     = ['type' => $type, 'status' => 'queued', 'payload' => $payload,
                    'last_error' => null, 'updated_at' => $now];
        if (Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->exists()) {
            Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->update($row);
            return;
        }
        Capsule::table(Schema::T_JOBS)->insert($row + [
            'serviceid' => $serviceId, 'attempts' => 0, 'created_at' => $now,
        ]);
    }

    /** 取出存的 $params（解密反序列化）；失败返回 null。 */
    public static function payload(int $serviceId): ?array
    {
        $r = Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->first();
        if (!$r || (string) ($r->payload ?? '') === '') {
            return null;
        }
        $raw = Config::decrypt((string) $r->payload);
        $arr = @unserialize($raw);
        return is_array($arr) ? $arr : null;
    }

    /** 当前状态：queued|running|done|failed|null（无任务）。 */
    public static function status(int $serviceId): ?string
    {
        $r = Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->first();
        return $r ? (string) $r->status : null;
    }

    /** 待处理任务：queued，或 failed 且未超重试次数。按 id 升序（先到先跑）。 */
    public static function due(int $maxAttempts = 3, int $limit = 20): array
    {
        return Capsule::table(Schema::T_JOBS)
            ->where(function ($q) use ($maxAttempts) {
                $q->where('status', 'queued')
                  ->orWhere(function ($w) use ($maxAttempts) {
                      $w->where('status', 'failed')->where('attempts', '<', $maxAttempts);
                  });
            })
            ->orderBy('id')->limit($limit)->get()->all();
    }

    public static function markRunning(int $serviceId): void
    {
        Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->update([
            'status' => 'running', 'attempts' => Capsule::raw('attempts + 1'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function markDone(int $serviceId): void
    {
        Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->update([
            'status' => 'done', 'last_error' => null, 'payload' => null, // 完成即清 payload（不留凭据）
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function markFailed(int $serviceId, string $error): void
    {
        Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->update([
            'status' => 'failed', 'last_error' => mb_substr($error, 0, 1000),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function remove(int $serviceId): void
    {
        Capsule::table(Schema::T_JOBS)->where('serviceid', $serviceId)->delete();
    }

    /** 存 payload 前剔除超大/无关字段（保留开通真正要用的：configoptions/customfields/clientsdetails/账密等）。 */
    private static function trimParams(array $params): array
    {
        unset($params['moduleparams']); // 可能含驱动内部大对象，开通不需要
        return $params;
    }
}
