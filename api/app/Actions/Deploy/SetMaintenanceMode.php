<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Deploy\ApplyMaintenanceMode;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SetMaintenanceMode
{
    /**
     * Create a new SetMaintenanceMode instance.
     *
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Put an environment's websites into maintenance mode (visitors see Laravel's 503 page; a secret link lets the team
     * in) or bring them back, on every server it deploys to (queued).
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  bool  $down
     * @return void
     */
    public function handle(User $actor, Environment $environment, bool $down): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $environment->forceFill($down
            ? ['maintenance_at' => now(), 'maintenance_secret' => Str::lower(Str::random(24)), 'maintenance_error' => null]
            : ['maintenance_at' => null, 'maintenance_secret' => null, 'maintenance_error' => null])->save();
        $this->audit->handle($down ? AuditAction::MaintenanceStarted : AuditAction::MaintenanceEnded, $actor, $environment->project->account_id, ['environment' => $environment->name], $environment->project_id);
        ApplyMaintenanceMode::dispatch($environment->id, $down)->afterCommit();
    }
}
