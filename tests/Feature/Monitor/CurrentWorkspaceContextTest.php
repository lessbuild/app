<?php

namespace Tests\Feature\Monitor;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class CurrentWorkspaceContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->string('plan')->default('free');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('monitor')->dropIfExists('user_workspace');
        Schema::connection('monitor')->dropIfExists('workspaces');
        Schema::connection('monitor')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_search_result_workspace_context_is_selected_only_when_the_user_is_a_member(): void
    {
        $userId = DB::connection('monitor')->table('users')->insertGetId([
            'name' => 'Monitor member',
            'email' => 'monitor-member@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'owner_id' => $userId,
            'name' => 'Search workspace',
            'slug' => 'search-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($userId);
        $request = $this->requestFor($user, $workspaceId);
        $workspace = (new CurrentWorkspace($request))->get();

        $this->assertSame($workspaceId, $workspace->getKey());
        $this->assertSame($workspaceId, $request->session()->get('workspace_id'));
    }

    public function test_search_result_cannot_select_another_users_workspace(): void
    {
        $userId = DB::connection('monitor')->table('users')->insertGetId([
            'name' => 'Monitor member',
            'email' => 'monitor-member@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'owner_id' => $userId + 1,
            'name' => 'Private workspace',
            'slug' => 'private-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            (new CurrentWorkspace($this->requestFor(User::query()->findOrFail($userId), $workspaceId)))->get();
            $this->fail('An unauthorized workspace must not become the active Monitor context.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function requestFor(User $user, int $workspaceId): Request
    {
        $request = Request::create('/incidents/4?workspace_id='.$workspaceId);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
