<?php

namespace Tests\Feature\Deployer;

use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationConfigurationLocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeployerDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployer_project_lock_stays_on_its_database_when_core_is_the_default(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create([
            'created_by' => $user->id,
            'name' => 'Isolated project',
            'slug' => 'isolated-project',
        ]);

        config(['database.default' => 'core']);

        try {
            $lockedProject = DB::connection('deployer')->transaction(
                fn (): Project => app(ApplicationConfigurationLocks::class)->project($project->id),
            );

            $this->assertSame('deployer', $lockedProject->getConnectionName());
            $this->assertSame($project->id, $lockedProject->id);
            $this->assertFalse(Schema::connection('core')->hasTable('projects'));
        } finally {
            config(['database.default' => 'deployer']);
        }
    }
}
