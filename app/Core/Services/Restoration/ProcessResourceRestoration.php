<?php

namespace App\Core\Services\Restoration;

use App\Core\Data\Restoration\NativeRestorationReceipt;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Exceptions\Restoration\ResourceRestorationSuperseded;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\ResourceRestorationRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProcessResourceRestoration
{
    public const MAX_ATTEMPTS = 12;

    public function __construct(private readonly ProductResourceRestorationRegistry $providers, private readonly ResourceRestorationAuthority $authority) {}

    public function process(string $requestId): string
    {
        // The claim commits before calling any product provider. Never wait for source
        // locks while holding Core locks: providers own the source -> Core lock order.
        $request = DB::connection('core')->transaction(function () use ($requestId): ?ResourceRestorationRequest {
            $request = ResourceRestorationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if ($request === null || $request->status !== 'pending' || $request->available_at?->isFuture()) {
                return null;
            }
            $request->forceFill([
                'status' => 'processing', 'attempts' => $request->attempts + 1,
                'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(10), 'last_attempted_at' => now(),
            ])->save();

            return $request;
        });
        if ($request === null) {
            return 'skipped';
        }
        $attempt = $request->attempt();
        try {
            $this->authority->assertAttempt($attempt);
            $provider = $this->providers->get($attempt->target->product, $attempt->target->resourceType);
            if ($provider === null) {
                throw new ResourceRestorationBlocked('restoration_unavailable');
            }
            $receipt = $provider->apply($attempt);
            $this->validateReceipt($request, $receipt);
            $result = 'skipped';
            $provider->withCurrentReceipt($attempt, function (NativeRestorationReceipt $current) use ($attempt, $receipt, &$result): void {
                if (! hash_equals($receipt->hash(), $current->hash())) {
                    throw new ResourceRestorationSuperseded('source_receipt_changed');
                }
                $result = DB::connection('core')->transaction(function () use ($attempt, $current): string {
                    if (DB::connection('core')->getDriverName() === 'sqlite') {
                        // SQLite ignores SELECT FOR UPDATE. Reserve its writer before
                        // authority reads so a concurrent revocation cannot slip past them.
                        $reserved = DB::connection('core')->table('resource_restoration_requests')
                            ->where('id', $attempt->requestId)->where('status', 'processing')
                            ->where('attempts', $attempt->generation)->where('lease_token', $attempt->leaseToken)
                            ->where('lease_expires_at', '>', now())->update(['lease_token' => $attempt->leaseToken]);
                        if ($reserved !== 1) {
                            throw new ResourceRestorationBlocked('restoration_lease_lost');
                        }
                    }
                    $locked = $this->authority->assertAttempt($attempt, lock: true);
                    $this->validateReceipt($locked, $current);
                    $this->project($locked, $current);
                    $locked->forceFill([
                        'status' => 'completed', 'receipt_hash' => $current->hash(), 'receipt_revision' => $current->revision,
                        'completed_at' => now(), 'available_at' => null, 'lease_token' => null, 'lease_expires_at' => null,
                        'last_error_code' => null, 'last_error_at' => null,
                    ])->save();

                    return 'completed';
                });
            });

            return $result;
        } catch (Throwable $exception) {
            return $this->failure($attempt, $exception);
        }
    }

    public function recoverExpiredLeases(): int
    {
        // Null leases cannot be claimed by old workers, even before the next generation.
        $expired = ResourceRestorationRequest::query()->where('status', 'processing')->where('lease_expires_at', '<=', now());
        $failed = (clone $expired)->where('attempts', '>=', self::MAX_ATTEMPTS)->update([
            'status' => 'failed', 'available_at' => null, 'lease_token' => null, 'lease_expires_at' => null,
            'last_error_code' => 'restoration_interruption_limit', 'last_error_at' => now(), 'updated_at' => now(),
        ]);
        $pending = $expired->where('attempts', '<', self::MAX_ATTEMPTS)->update([
            'status' => 'pending', 'available_at' => now(), 'lease_token' => null, 'lease_expires_at' => null,
            'last_error_code' => 'restoration_lease_expired', 'last_error_at' => now(), 'updated_at' => now(),
        ]);

        return $failed + $pending;
    }

    private function validateReceipt(ResourceRestorationRequest $request, NativeRestorationReceipt $receipt): void
    {
        if ($receipt->requestId !== (string) $request->getKey() || $receipt->revision < $request->expected_revision
            || $receipt->toArray()['states'] !== $request->requested_states) {
            throw new ResourceRestorationSuperseded('source_receipt_changed');
        }
    }

    private function project(ResourceRestorationRequest $request, NativeRestorationReceipt $receipt): void
    {
        $productBinding = $request->mapping_bindings['_project_product'];
        if ($productBinding['status'] === 'inactive') {
            // Only importer-owned application archival can deactivate this attachment.
            // Workspace grants, subscriptions and other products remain independent.
            $product = ProjectProduct::query()->findOrFail($productBinding['id']);
            $metadata = $product->metadata ?? [];
            unset($metadata['archive_origin']);
            $product->forceFill(['status' => 'active', 'metadata' => $metadata])->save();
        }
        foreach ($receipt->states as $state) {
            $binding = $request->mapping_bindings[$state->resourceType.':'.$state->resourceId] ?? null;
            if ($binding === null) {
                continue;
            }
            $resource = ProjectResource::query()->findOrFail($binding['resource_id']);
            $environment = $binding['environment_id'] === null ? null : ProjectEnvironment::query()->findOrFail($binding['environment_id']);
            $child = $request->resource_type === 'application' && $state->resourceType === 'environment';
            // A source parent restore cannot undo independent canonical archival.
            if ($child && (($resource->status === 'archived' && ($binding['resource_provenance']['archive_origin'] ?? null) !== 'parent_application')
                || ($environment?->status === 'archived' && ($binding['environment_provenance']['archive_origin'] ?? null) !== 'parent_application'))) {
                continue;
            }
            $resourceMetadata = $resource->metadata ?? [];
            if ($state->status !== 'archived') {
                unset($resourceMetadata['archive_origin']);
            }
            $resource->forceFill(['status' => $state->status, 'metadata' => $resourceMetadata])->save();
            if ($environment !== null && ! collect($binding['environment_resources'])->contains(fn (array $other): bool => (string) $other['id'] !== (string) $binding['resource_id'])) {
                $environmentMetadata = $environment->metadata ?? [];
                if ($state->status !== 'archived') {
                    unset($environmentMetadata['archive_origin']);
                }
                $environment->forceFill(['status' => $state->status, 'metadata' => $environmentMetadata])->save();
            }
        }
    }

    private function failure(ResourceRestorationAttempt $attempt, Throwable $exception): string
    {
        return DB::connection('core')->transaction(function () use ($attempt, $exception): string {
            $request = ResourceRestorationRequest::query()->whereKey($attempt->requestId)->lockForUpdate()->first();
            if ($request === null || $request->status !== 'processing' || $request->attempts !== $attempt->generation
                || ! hash_equals((string) $request->lease_token, $attempt->leaseToken)
                || $request->lease_expires_at?->isFuture() !== true) {
                return 'skipped';
            }
            $blocked = $exception instanceof ResourceRestorationBlocked || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException || $exception instanceof ValidationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
            $status = $exception instanceof ResourceRestorationSuperseded ? 'superseded'
                : ($blocked ? 'blocked' : ($request->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending'));
            $code = $exception instanceof ResourceRestorationBlocked ? $exception->reasonCode
                : ($blocked ? 'restoration_authority_changed' : 'restoration_temporarily_unavailable');
            // Providers supply reason codes only; exception messages and source payloads never persist.
            $code = preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $code) ? $code : 'restoration_failed';
            $request->forceFill([
                'status' => $status, 'lease_token' => null, 'lease_expires_at' => null,
                'available_at' => $status === 'pending' ? now()->addSeconds(min(86400, 60 * (2 ** min(10, max(0, $request->attempts - 1))))) : null,
                'last_error_code' => $code, 'last_error_at' => now(),
            ])->save();

            return $status;
        });
    }
}
