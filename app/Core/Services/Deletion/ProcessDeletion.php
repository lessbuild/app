<?php

namespace App\Core\Services\Deletion;

use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ResourceRestorationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessDeletion
{
    public const MAX_ATTEMPTS = 12;

    public function __construct(private readonly DeletionAuthority $authority, private readonly ProductDeletionRegistry $providers, private readonly FinalizeDeletion $finalize) {}

    public function process(string $stepId): string
    {
        $step = DB::connection('core')->transaction(function () use ($stepId): ?DeletionStep {
            $step = DeletionStep::query()->whereKey($stepId)->lockForUpdate()->first();
            if ($step === null || $step->status !== 'pending' || $step->available_at?->isFuture()) {
                return null;
            }
            $request = DeletionRequest::query()->findOrFail($step->deletion_request_id);
            if ($request->status === 'completed' || $request->phase !== $step->phase
                || ($step->phase === 'purge' && $step->kind === 'account' && $request->steps()->where('kind', 'workspace')->where('status', '!=', 'completed')->exists())) {
                return null;
            }
            $step->forceFill(['status' => 'processing', 'attempts' => $step->attempts + 1,
                'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(10)])->save();

            return $step;
        }, attempts: 3);
        if ($step === null) {
            return 'skipped';
        }
        $attempt = $step->attempt();
        try {
            $this->authority->assertAttempt($attempt);
            $provider = $this->providers->get($step->product);
            if ($provider === null) {
                throw new DeletionBlocked('product_cleanup_unavailable');
            }
            // No Core transaction is held across a product operation.
            $result = $attempt->phase === 'prepare' ? $provider->prepare($attempt) : $provider->purge($attempt);
        } catch (Throwable $exception) {
            $result = $exception instanceof DeletionBlocked
                ? new ProductDeletionResult('blocked', $exception->reasonCode)
                : new ProductDeletionResult('waiting', 'cleanup_temporarily_unavailable');
        }
        $status = $this->record($attempt, $result);
        $this->advance($attempt->requestId);

        return $status;
    }

    private function record(ProductDeletionAttempt $attempt, ProductDeletionResult $result): string
    {
        return DB::connection('core')->transaction(function () use ($attempt, $result): string {
            $step = DeletionStep::query()->whereKey($attempt->stepId)->lockForUpdate()->first();
            if ($step === null || $step->status !== 'processing' || $step->attempts !== $attempt->generation
                || ! hash_equals((string) $step->lease_token, $attempt->leaseToken) || $step->lease_expires_at?->isFuture() !== true) {
                return 'skipped';
            }
            $allowed = $attempt->phase === 'prepare' ? ['ready', 'waiting', 'blocked'] : ['completed', 'waiting', 'blocked'];
            $status = in_array($result->status, $allowed, true) ? $result->status : 'blocked';
            $code = $result->reasonCode;
            if (! in_array($result->status, $allowed, true)) {
                $code = 'invalid_cleanup_acknowledgement';
            }
            if ($status === 'waiting') {
                $status = $step->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            }
            $code = is_string($code) && preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $code) ? $code : null;
            $retained = array_values(array_filter($result->retained, fn ($value): bool => is_string($value) && strlen($value) <= 500));
            $step->forceFill(['status' => $status, 'last_error_code' => $code, 'retained' => $retained,
                'lease_token' => null, 'lease_expires_at' => null,
                'available_at' => $status === 'pending' ? now()->addSeconds(min(3600, 30 * 2 ** min(7, $step->attempts - 1))) : null,
                'completed_at' => $status === 'completed' ? now() : null])->save();

            return $status;
        }, attempts: 3);
    }

    public function advance(string $requestId): void
    {
        DB::connection('core')->transaction(function () use ($requestId): void {
            $request = DeletionRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status === 'completed') {
                return;
            }
            $steps = $request->steps()->lockForUpdate()->get();
            if ($request->phase === 'prepare' && ! $steps->contains(fn (DeletionStep $step): bool => $step->status !== 'ready')) {
                $projects = Project::query()->whereIn('workspace_id', $request->workspace_ids)->pluck('id');
                $connections = ProjectConnection::query()->whereIn('project_id', $projects)->pluck('id');
                // Already claimed cross-product work drains before any source purge.
                if (ProjectConnectionDelivery::query()->whereIn('project_connection_id', $connections)->where('status', 'processing')->exists()
                    || ResourceRestorationRequest::query()->whereIn('workspace_id', $request->workspace_ids)->where('status', 'processing')->exists()) {
                    $request->forceFill(['last_error_code' => 'core_activity_draining'])->save();

                    return;
                }
                $request->steps()->update(['phase' => 'purge', 'status' => 'pending', 'available_at' => now(), 'updated_at' => now()]);
                $request->forceFill(['phase' => 'purge', 'status' => 'pending', 'last_error_code' => null])->save();
            } elseif ($request->phase === 'purge' && ! $steps->contains(fn (DeletionStep $step): bool => $step->status !== 'completed')) {
                try {
                    $this->finalize->handle($request);
                } catch (DeletionBlocked $exception) {
                    $request->forceFill(['status' => 'blocked', 'last_error_code' => $exception->reasonCode])->save();
                }
            } else {
                $request->forceFill(['status' => $steps->contains(fn (DeletionStep $step): bool => in_array($step->status, ['blocked', 'failed'], true)) ? 'blocked' : 'pending'])->save();
            }
        }, attempts: 3);
    }

    public function recover(): int
    {
        $expired = DeletionStep::query()->where('status', 'processing')->where('lease_expires_at', '<=', now());
        $failed = (clone $expired)->where('attempts', '>=', self::MAX_ATTEMPTS)->update(['status' => 'failed', 'last_error_code' => 'cleanup_lease_expired', 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => null, 'updated_at' => now()]);
        $pending = $expired->where('attempts', '<', self::MAX_ATTEMPTS)->update(['status' => 'pending', 'last_error_code' => 'cleanup_lease_expired', 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => now(), 'updated_at' => now()]);

        return $failed + $pending;
    }

    /** Caller has already checked the independent deletion receipt capability. */
    public function retry(DeletionRequest $request): void
    {
        DB::connection('core')->transaction(function () use ($request): void {
            $request = DeletionRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($request->status === 'completed') {
                return;
            }
            $request->steps()->whereIn('status', ['blocked', 'failed'])->update(['status' => 'pending', 'available_at' => now(), 'last_error_code' => null, 'updated_at' => now()]);
            $request->forceFill(['status' => 'pending', 'last_error_code' => null])->save();
        }, attempts: 3);
    }
}
