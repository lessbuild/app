<?php

namespace App\Core\Services\Blueprints;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintStep;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProcessProjectBlueprint
{
    public function __construct(
        private readonly ProjectBlueprintProviderRegistry $providers,
        private readonly BlueprintAuthority $authority,
        private readonly RecordBlueprintResources $resources,
    ) {}

    public function handle(string $stepId): void
    {
        $attempt = $this->claim($stepId);
        if ($attempt === null) {
            return;
        }
        try {
            $this->authority->assertAttempt($attempt);
            $provider = $this->providers->get($attempt->product);
            if ($provider === null) {
                throw new BlueprintBlocked('product_unavailable');
            }
            // The module commits its idempotent receipt in its own transaction.
            // There is deliberately no open Core transaction while it works.
            $result = $provider->apply($attempt);
            DB::connection('core')->transaction(function () use ($attempt, $result): void {
                $this->reserveCoreTarget($attempt);
                $step = $this->authority->assertAttempt($attempt);
                $receipt = $this->resources->handle($attempt, $result);
                $this->authority->authorize($attempt->target, $attempt->product);
                $step->forceFill(['status' => 'completed', 'result' => $receipt, 'completed_at' => now(),
                    'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => null, 'available_at' => null])->save();
                $this->refreshRun($attempt->runId);
            });
        } catch (BlueprintBlocked $exception) {
            $this->failed($attempt, $exception->reason, in_array($exception->reason, ['product_unavailable', 'source_unavailable', 'worker_lease_lost'], true));
        } catch (AuthorizationException) {
            $this->failed($attempt, 'authority_changed', false);
        } catch (ValidationException) {
            $this->failed($attempt, 'native_binding_changed', false);
        } catch (HttpExceptionInterface $exception) {
            $reason = match ($exception->getStatusCode()) {
                401, 403, 404 => 'authority_changed',
                409, 422 => 'native_binding_changed',
                410 => 'source_fenced',
                default => 'source_unavailable',
            };
            $this->failed($attempt, $reason, $reason === 'source_unavailable');
        } catch (Throwable) {
            // Persist only a stable code. Native exceptions may include private configuration.
            $this->failed($attempt, 'source_unavailable', true);
        }
    }

    private function claim(string $stepId): ?BlueprintStepAttempt
    {
        return DB::connection('core')->transaction(function () use ($stepId): ?BlueprintStepAttempt {
            DB::connection('core')->table('project_blueprint_steps')->where('id', $stepId)->update(['id' => DB::raw('id')]);
            $step = ProjectBlueprintStep::query()->whereKey($stepId)->lockForUpdate()->first();
            if ($step === null || ! in_array($step->status, ['pending', 'waiting', 'processing'], true)
                || ($step->status === 'processing' && $step->lease_expires_at?->isFuture())
                || ($step->available_at !== null && $step->available_at->isFuture())) {
                return null;
            }
            $step->forceFill(['status' => 'processing', 'generation' => $step->generation + 1, 'lease_token' => Str::random(64),
                'lease_expires_at' => now()->addMinutes(10), 'available_at' => null])->save();
            ProjectBlueprintRun::query()->whereKey($step->project_blueprint_run_id)->where('status', 'pending')->update(['status' => 'processing', 'updated_at' => now()]);

            return $step->attempt();
        });
    }

    private function reserveCoreTarget(BlueprintStepAttempt $attempt): void
    {
        foreach (['users' => $attempt->target->actorId, 'workspaces' => $attempt->target->workspaceId,
            'projects' => $attempt->target->projectId, 'project_blueprint_steps' => $attempt->stepId] as $table => $id) {
            DB::connection('core')->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
            DB::connection('core')->table($table)->where('id', $id)->lockForUpdate()->first();
        }
    }

    private function failed(BlueprintStepAttempt $attempt, string $reason, bool $retryable): void
    {
        DB::connection('core')->transaction(function () use ($attempt, $reason, $retryable): void {
            $step = ProjectBlueprintStep::query()->whereKey($attempt->stepId)->lockForUpdate()->first();
            if ($step === null || $step->status !== 'processing' || $step->generation !== $attempt->generation
                || ! hash_equals((string) $step->lease_token, $attempt->leaseToken)) {
                return;
            }
            $retryable = $retryable && $step->generation < 12;
            $step->forceFill(['status' => $retryable ? 'waiting' : 'blocked', 'last_error_code' => $reason,
                'available_at' => $retryable ? now()->addSeconds(min(3600, 15 * (2 ** min(8, $step->generation - 1)))) : null,
                'lease_token' => null, 'lease_expires_at' => null])->save();
            $this->refreshRun($attempt->runId);
        });
    }

    private function refreshRun(string $runId): void
    {
        $states = ProjectBlueprintStep::query()->where('project_blueprint_run_id', $runId)->pluck('status');
        $complete = $states->isNotEmpty() && $states->every(fn (string $state): bool => $state === 'completed');
        ProjectBlueprintRun::query()->whereKey($runId)->update(['status' => $complete ? 'completed' : ($states->contains('blocked') ? 'blocked' : 'processing'),
            'completed_at' => $complete ? now() : null, 'updated_at' => now()]);
    }
}
