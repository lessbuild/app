<?php

namespace App\Core\Services\Blueprints;

use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectBlueprint;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Support\Facades\DB;

final class SaveProjectBlueprintVersion
{
    public function __construct(private readonly NormalizeProjectBlueprint $normalize, private readonly WorkspaceProjectAccess $access) {}

    public function handle(PlatformUser $actor, Workspace $workspace, string $name, ?string $description, array $definition, ?ProjectBlueprint $blueprint = null): ProjectBlueprintVersion
    {
        abort_unless($this->access->canManageWorkspace($actor, $workspace), 403);
        $definition = $this->normalize->handle($definition);

        return DB::connection('core')->transaction(function () use ($actor, $workspace, $name, $description, $definition, $blueprint): ProjectBlueprintVersion {
            DB::connection('core')->table('workspaces')->where('id', $workspace->getKey())->update(['id' => DB::raw('id')]);
            $workspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($this->access->canManageWorkspace($actor, $workspace), 403);
            $membership = $this->access->activeMembership($actor, $workspace);
            foreach (array_keys($definition['products']) as $product) {
                abort_unless($membership !== null && $this->access->hasProductAccess($membership, $product), 403);
            }
            if ($blueprint !== null) {
                $blueprint = ProjectBlueprint::query()->whereKey($blueprint->getKey())->where('workspace_id', $workspace->getKey())
                    ->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            } else {
                $blueprint = ProjectBlueprint::query()->create(['workspace_id' => $workspace->getKey(), 'created_by_user_id' => $actor->getKey(), 'name' => $name, 'description' => $description]);
            }
            $version = ProjectBlueprintVersion::query()->create([
                'project_blueprint_id' => $blueprint->getKey(), 'created_by_user_id' => $actor->getKey(),
                'version' => $blueprint->latest_version + 1, 'definition' => $definition, 'definition_hash' => BlueprintFingerprint::make($definition),
            ]);
            $blueprint->forceFill(['name' => $name, 'description' => $description, 'latest_version' => $version->version])->save();

            return $version;
        });
    }
}
