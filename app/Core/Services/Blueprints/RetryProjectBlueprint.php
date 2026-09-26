<?php

namespace App\Core\Services\Blueprints;

use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintStep;
use Illuminate\Support\Facades\DB;

final class RetryProjectBlueprint
{
    public function __construct(private readonly BlueprintAuthority $authority) {}

    public function handle(PlatformUser $actor, ProjectBlueprintRun $run): void
    {
        // A different manager must not silently assume the accepted actor's native credentials.
        abort_unless((string) $run->requested_by_user_id === (string) $actor->getKey(), 403);
        DB::connection('core')->transaction(function () use ($run): void {
            $run = ProjectBlueprintRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            foreach (ProjectBlueprintStep::query()->where('project_blueprint_run_id', $run->getKey())->whereIn('status', ['blocked', 'waiting'])->lockForUpdate()->get() as $step) {
                $target = new BlueprintTarget(...$step->target);
                $this->authority->authorize($target, $step->product);
                $step->forceFill(['status' => 'pending', 'available_at' => now(), 'last_error_code' => null])->save();
            }
            if ($run->status !== 'completed') {
                $run->forceFill(['status' => 'pending'])->save();
            }
        });
    }
}
