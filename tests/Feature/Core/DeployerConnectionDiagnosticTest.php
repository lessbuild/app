<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectResource;
use App\Core\Services\Connections\ProjectConnectionDiagnosticRegistry;
use App\Core\Services\Connections\ProjectConnectionDiagnostics;
use App\Modules\Deployer\Services\Core\DeployerProjectConnectionDiagnosticProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeployerConnectionDiagnosticTest extends TestCase
{
    private const USER_ID = '01J8AA00000000000000000000';

    private const PROJECT_ID = '01J8AA00000000000000000001';

    private const PROJECT_RESOURCE_ID = '01J8AA00000000000000000011';

    private const ENVIRONMENT_RESOURCE_ID = '01J8AA00000000000000000012';

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.products.deployer.auth_authority' => 'legacy']);
        Http::preventStrayRequests();
        $this->createCoreTables();
        $this->createDeployerTables();
        $this->seedAuthorizedEnvironment();
    }

    protected function tearDown(): void
    {
        foreach (['legacy_identity_maps', 'project_resources', 'projects'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        foreach ([
            'provider_connection_checks', 'providers', 'repositories', 'websites', 'servers', 'environments',
            'projects', 'organization_user', 'organizations', 'users',
        ] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_rejected_provider_credentials_are_explained_without_network_requests_or_secret_disclosure(): void
    {
        $this->addProviderCheck(successful: false, httpStatus: 401, checkedAt: now()->subMinutes(3));

        $diagnostic = $this->diagnostics()->forConnection($this->connection(), $this->platformUser());
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertSame('Credential rejected', $diagnostic->status);
        $this->assertStringContainsString('may have expired, been revoked', $diagnostic->detail);
        $this->assertStringContainsString('Last provider check', $html);
        $this->assertStringNotContainsString('provider-secret-token', $html);
        $this->assertStringNotContainsString('private provider response', $html);
        Http::assertNothingSent();

        DB::connection('deployer')->table('provider_connection_checks')->update(['http_status' => 403]);
        $permissionDiagnostic = $this->diagnostics()->forConnection($this->connection(), $this->platformUser());

        $this->assertSame('Provider permission denied', $permissionDiagnostic->status);
        $this->assertStringContainsString('Review the provider credential scopes', $permissionDiagnostic->nextStep);
        Http::assertNothingSent();

        DB::connection('deployer')->table('organization_user')->delete();
        $inaccessibleDiagnostic = $this->diagnostics()->forConnection($this->connection(), $this->platformUser());

        $this->assertSame('Ready', $inaccessibleDiagnostic->status);
        $this->assertStringNotContainsString('Git provider', $inaccessibleDiagnostic->detail);
    }

    public function test_successful_check_that_is_overdue_is_not_reported_as_current(): void
    {
        $this->addProviderCheck(successful: true, httpStatus: 200, checkedAt: now()->subMinutes(1441));

        $diagnostic = $this->diagnostics()->forConnection($this->connection(), $this->platformUser());

        $this->assertSame('Credential check overdue', $diagnostic->status);
        $this->assertNotNull($diagnostic->lastObservedAt);
    }

    public function test_provider_without_a_stored_check_is_shown_as_unverified(): void
    {
        $diagnostic = $this->diagnostics()->forConnection($this->connection(), $this->platformUser());

        $this->assertSame('Credential not checked', $diagnostic->status);
        $this->assertStringContainsString('does not contact providers', $diagnostic->detail);
        Http::assertNothingSent();
    }

    private function diagnostics(): ProjectConnectionDiagnostics
    {
        $provider = app(ProjectConnectionDiagnosticRegistry::class)->get('deployer');
        $this->assertInstanceOf(DeployerProjectConnectionDiagnosticProvider::class, $provider);

        return app(ProjectConnectionDiagnostics::class);
    }

    private function connection(): ProjectConnection
    {
        $connection = (new ProjectConnection)->forceFill([
            'project_id' => self::PROJECT_ID,
            'status' => 'active',
        ]);
        $connection->setRelation('deliveries', collect());
        $connection->setRelation('sourceResource', null);
        $connection->setRelation('targetResource', ProjectResource::query()->findOrFail(self::ENVIRONMENT_RESOURCE_ID));

        return $connection;
    }

    private function platformUser(): PlatformUser
    {
        $user = new PlatformUser;
        $user->setAttribute('id', self::USER_ID);
        $user->setAttribute('status', 'active');

        return $user;
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('status');
            $table->timestamps();
        });
    }

    private function createDeployerTables(): void
    {
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_organization_id')->nullable();
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
        });
        Schema::connection('deployer')->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
        });
        Schema::connection('deployer')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();
        });
        Schema::connection('deployer')->create('servers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('provider_id')->nullable();
        });
        Schema::connection('deployer')->create('websites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->softDeletes();
        });
        Schema::connection('deployer')->create('repositories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->softDeletes();
        });
        Schema::connection('deployer')->create('providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('name');
            $table->text('token')->nullable();
            $table->string('connection_status')->nullable();
            $table->timestamp('connection_checked_at')->nullable();
            $table->unsignedInteger('connection_check_interval_minutes')->default(1440);
            $table->boolean('connection_monitoring_enabled')->default(true);
            $table->unsignedSmallInteger('connection_failure_count')->default(0);
            $table->unsignedSmallInteger('connection_failure_threshold')->default(1);
            $table->softDeletes();
        });
        Schema::connection('deployer')->create('provider_connection_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('provider_id');
            $table->boolean('successful');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('checked_at');
        });
    }

    private function seedAuthorizedEnvironment(): void
    {
        DB::connection('core')->table('projects')->insert([
            'id' => self::PROJECT_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => '01J8AA00000000000000000010',
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => '17',
            'canonical_entity' => 'user',
            'canonical_id' => self::USER_ID,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            [
                'id' => self::PROJECT_RESOURCE_ID,
                'project_id' => self::PROJECT_ID,
                'product' => 'deployer',
                'resource_type' => 'project',
                'resource_id' => '31',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => self::ENVIRONMENT_RESOURCE_ID,
                'project_id' => self::PROJECT_ID,
                'product' => 'deployer',
                'resource_type' => 'environment',
                'resource_id' => '41',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::connection('deployer')->table('users')->insert([
            'id' => 17,
            'current_organization_id' => 50,
        ]);
        DB::connection('deployer')->table('organizations')->insert([
            'id' => 50,
            'owner_id' => 18,
        ]);
        DB::connection('deployer')->table('organization_user')->insert([
            'organization_id' => 50,
            'user_id' => 17,
            'role' => 'developer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('projects')->insert([
            'id' => 31,
            'organization_id' => 50,
        ]);
        DB::connection('deployer')->table('environments')->insert([
            'id' => 41,
            'project_id' => 31,
            'server_id' => null,
            'website_id' => 81,
        ]);
        DB::connection('deployer')->table('websites')->insert([
            'id' => 81,
            'server_id' => null,
            'deleted_at' => null,
        ]);
        DB::connection('deployer')->table('repositories')->insert([
            'id' => 91,
            'website_id' => 81,
            'provider_id' => 72,
            'deleted_at' => null,
        ]);
        DB::connection('deployer')->table('providers')->insert([
            'id' => 72,
            'provider' => 'gitlab',
            'name' => 'Git provider',
            'token' => 'provider-secret-token',
            'connection_status' => null,
            'connection_check_interval_minutes' => 1440,
            'connection_monitoring_enabled' => true,
            'connection_failure_count' => 0,
            'connection_failure_threshold' => 1,
            'deleted_at' => null,
        ]);
    }

    private function addProviderCheck(bool $successful, ?int $httpStatus, mixed $checkedAt): void
    {
        DB::connection('deployer')->table('providers')->where('id', 72)->update([
            'connection_status' => $successful ? 'healthy' : 'failed',
            'connection_checked_at' => $checkedAt,
        ]);
        DB::connection('deployer')->table('provider_connection_checks')->insert([
            'provider_id' => 72,
            'successful' => $successful,
            'http_status' => $httpStatus,
            'checked_at' => $checkedAt,
        ]);
    }
}
