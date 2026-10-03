<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\EnvironmentVariable;
use App\Models\PendingVariableChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DecideVariableChange
{
    /**
     * Create a new DecideVariableChange instance.
     *
     * @param  SaveEnvironmentVariable  $save  Applies a saved variable.
     * @param  ReplaceEnvironmentVariables  $replace  Applies a replacement.
     */
    public function __construct(private readonly SaveEnvironmentVariable $save, private readonly ReplaceEnvironmentVariables $replace) {}

    /**
     * Approve (and apply) or reject a pending change. The person deciding must be someone other than who asked, and
     * allowed to configure the environment.
     *
     * @param  User  $approver
     * @param  PendingVariableChange  $change
     * @param  bool  $approve
     * @return void
     */
    public function handle(User $approver, PendingVariableChange $change, bool $approve): void
    {
        $environment = $change->environment;
        Gate::forUser($approver)->authorize('configureDeploy', $environment);
        if ($change->requested_by === $approver->id) {
            throw ValidationException::withMessages(['change' => __('Someone else needs to approve your change.')]);
        }
        DB::transaction(function () use ($approver, $change, $approve, $environment): void {
            $locked = PendingVariableChange::query()->lockForUpdate()->findOrFail($change->id);
            StateConflict::unless($locked->status === 'pending', __('This change was already decided.'));
            if ($approve) {
                $payload = $locked->payload;
                match ($locked->kind) {
                    'save' => $this->save->handle($approver, $environment, [
                        'key' => (string) $payload['key'], 'value' => (string) $payload['value'], 'is_secret' => (bool) $payload['is_secret'],
                        'scope' => (string) $payload['scope'], 'rotation_due_at' => $payload['rotation_due_at'] ?? null,
                    ], approved: true),
                    'replace' => $this->replace->handle($approver, $environment, (string) $payload['contents'], approved: true),
                    default => EnvironmentVariable::query()->where('environment_id', $environment->id)->whereKey((int) $payload['variable_id'])->delete(),
                };
            }
            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approver->id, 'decided_at' => now()])->save();
        });
    }
}
