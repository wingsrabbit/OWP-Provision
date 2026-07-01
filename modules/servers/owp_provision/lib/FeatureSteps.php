<?php
/**
 * OWP Provision — lib/FeatureSteps.php
 * ----------------------------------------------------------------------------
 * Small feature/step runner for Project / Blueprint provisioning.
 *
 * Each step exposes validate/reserve/apply/verify/rollback/terminate. The first
 * implementation intentionally keeps callbacks lightweight while giving future
 * OpenVPN/IKEv2/dedicated variants a stable extension point.
 *
 * @target WHMCS 9.0.4 / PHP 8.3
 */

namespace OwpProvision;

if (!defined('WHMCS')) {
    die('Access Denied');
}

class BlueprintContext
{
    public array $params;
    public ?object $project;
    public int $serviceId;
    public array $state = [];

    public function __construct(array $params, ?object $project)
    {
        $this->params = $params;
        $this->project = $project;
        $this->serviceId = (int) ($params['serviceid'] ?? 0);
    }

    public function projectKey(): string
    {
        return (string) ($this->project->project_key ?? Projects::resolveKey($this->params));
    }

    public function binding(string $key, $default = null)
    {
        return Projects::binding($this->project, $key, $default);
    }

    public function bindingInt(string $key, int $default = 0): int
    {
        return Projects::bindingInt($this->project, $key, $default);
    }

    public function set(string $key, $value): void
    {
        $this->state[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->state) ? $this->state[$key] : $default;
    }
}

interface FeatureStep
{
    public function key(): string;
    public function validate(BlueprintContext $ctx): void;
    public function reserve(BlueprintContext $ctx): void;
    public function apply(BlueprintContext $ctx): void;
    public function verify(BlueprintContext $ctx): void;
    public function rollback(BlueprintContext $ctx): void;
    public function terminate(BlueprintContext $ctx): void;
}

abstract class AbstractFeatureStep implements FeatureStep
{
    protected string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function validate(BlueprintContext $ctx): void {}
    public function reserve(BlueprintContext $ctx): void {}
    public function apply(BlueprintContext $ctx): void {}
    public function verify(BlueprintContext $ctx): void {}
    public function rollback(BlueprintContext $ctx): void {}
    public function terminate(BlueprintContext $ctx): void {}
}

class CallbackFeatureStep extends AbstractFeatureStep
{
    /** @var array<string,callable> */
    private array $callbacks;

    /** @param array<string,callable> $callbacks */
    public function __construct(string $key, array $callbacks)
    {
        parent::__construct($key);
        $this->callbacks = $callbacks;
    }

    public function validate(BlueprintContext $ctx): void { $this->call('validate', $ctx); }
    public function reserve(BlueprintContext $ctx): void { $this->call('reserve', $ctx); }
    public function apply(BlueprintContext $ctx): void { $this->call('apply', $ctx); }
    public function verify(BlueprintContext $ctx): void { $this->call('verify', $ctx); }
    public function rollback(BlueprintContext $ctx): void { $this->call('rollback', $ctx); }
    public function terminate(BlueprintContext $ctx): void { $this->call('terminate', $ctx); }

    private function call(string $phase, BlueprintContext $ctx): void
    {
        if (isset($this->callbacks[$phase])) {
            ($this->callbacks[$phase])($ctx, $this);
        }
    }
}

class FeatureStepRunner
{
    /** @param FeatureStep[] $steps */
    public static function create(BlueprintContext $ctx, array $steps): void
    {
        foreach ($steps as $step) {
            $step->validate($ctx);
        }
        $done = [];
        try {
            foreach ($steps as $step) {
                $step->reserve($ctx);
                $done[] = $step;
                $step->apply($ctx);
                $step->verify($ctx);
            }
        } catch (\Throwable $e) {
            for ($i = count($done) - 1; $i >= 0; $i--) {
                try {
                    $done[$i]->rollback($ctx);
                } catch (\Throwable $rollbackError) {
                    if (function_exists('logModuleCall')) {
                        logModuleCall('owp_provision', 'feature_rollback', [
                            'serviceid' => $ctx->serviceId,
                            'project' => $ctx->projectKey(),
                            'step' => $done[$i]->key(),
                        ], $rollbackError->getMessage(), '');
                    }
                }
            }
            throw $e;
        }
    }

    /** @param FeatureStep[] $steps */
    public static function terminate(BlueprintContext $ctx, array $steps): void
    {
        for ($i = count($steps) - 1; $i >= 0; $i--) {
            $steps[$i]->terminate($ctx);
        }
    }
}
