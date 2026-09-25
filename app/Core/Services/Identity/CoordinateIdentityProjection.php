<?php

namespace App\Core\Services\Identity;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** A durable barrier between first-use product provisioning and accepted deletion. */
final class CoordinateIdentityProjection
{
    public function run(string $product, PlatformUser|Workspace $target, Closure $operation): mixed
    {
        $kind = $target instanceof Workspace ? 'workspace' : 'account';
        $actorId = (string) ($target instanceof Workspace ? $target->owner_user_id : $target->getKey());
        $canonicalId = (string) $target->getKey();
        $token = Str::random(48);
        $db = DB::connection('core');
        $id = $db->transaction(function () use ($db, $actorId, $canonicalId, $kind, $product, $token): string {
            // Also reserve SQLite's writer before reading a snapshot used to authorize projection.
            $db->table('users')->where('id', $actorId)->update(['id' => DB::raw('id')]);
            $actor = PlatformUser::query()->lockForUpdate()->findOrFail($actorId);
            abort_unless($actor->status === 'active', 403);
            if ($kind === 'workspace') {
                $workspace = Workspace::query()->lockForUpdate()->findOrFail($canonicalId);
                abort_unless($workspace->status === 'active' && $workspace->archived_at === null
                    && (string) $workspace->owner_user_id === $actorId, 409, 'The workspace is not active.');
            }
            $row = $db->table('identity_projection_operations')->where('kind', $kind)
                ->where('canonical_id', $canonicalId)->where('product', $product)->lockForUpdate()->first();
            // Never steal a live or crashed worker's claim based on elapsed time. A caught
            // failure has finished its source work and can be retried by the next visit.
            abort_if($row !== null && $row->status === 'processing', 409, 'This app connection is in progress. Interrupted setup needs reconciliation.');
            $id = $row?->id ?? (string) Str::ulid();
            $db->table('identity_projection_operations')->updateOrInsert(['id' => $id], [
                'actor_id' => $actorId, 'kind' => $kind, 'canonical_id' => $canonicalId, 'product' => $product,
                'token' => $token, 'status' => 'processing', 'created_at' => $row?->created_at ?? now(), 'updated_at' => now(),
            ]);

            return $id;
        }, attempts: 3);

        // No Core transaction is held while a native database is being changed. Exceptions
        // deliberately keep the barrier: unknown source outcomes need reconciliation, not expiry.
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $db->table('identity_projection_operations')->where('id', $id)->where('token', $token)
                ->update(['status' => 'failed', 'updated_at' => now()]);

            throw $exception;
        }
        $db->table('identity_projection_operations')->where('id', $id)->where('token', $token)->delete();

        return $result;
    }
}
