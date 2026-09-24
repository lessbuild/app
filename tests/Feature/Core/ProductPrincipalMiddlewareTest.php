<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\MappedProductPrincipalAdapter;
use App\Core\Services\Identity\ProductPrincipalProvisionerRegistry;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Services\Core\AnalyticsPlatformPrincipalProvisioner;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Deployer\Services\Core\DeployerPlatformPrincipalProvisioner;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Services\Core\MonitorPlatformPrincipalProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductPrincipalMiddlewareTest extends TestCase
{
    /** @var array<string, array{connection:string,model:class-string}> */
    private array $products = [
        'deployer' => ['connection' => 'deployer', 'model' => DeployerUser::class],
        'monitor' => ['connection' => 'monitor', 'model' => MonitorUser::class],
        'analytics' => ['connection' => 'analytics', 'model' => AnalyticsUser::class],
    ];

    private PlatformUser $platformUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerProvisioners();

        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
            $table->string('batch_key')->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        foreach (array_unique(array_column($this->products, 'connection')) as $connection) {
            Schema::connection($connection)->create('users', function (Blueprint $table): void {
                $table->id();
                $table->ulid('platform_user_id')->nullable()->unique();
                $table->string('name');
                $table->string('email');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->string('auth_type')->nullable();
                $table->timestamp('password_set_at')->nullable();
                $table->unsignedBigInteger('current_organization_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
            $table->primary(['organization_id', 'user_id']);
        });

        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
            $table->primary(['user_id', 'workspace_id']);
        });

        $this->platformUser = (new PlatformUser)->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Platform account',
            'email' => 'platform@example.test',
            'password' => Hash::make('platform account password'),
            'password_set_at' => now(),
            'status' => 'active',
        ]);

        $registry = app(ProductPrincipalRegistry::class);

        foreach ($this->products as $product => $source) {
            if ($product === 'deployer') {
                continue;
            }

            $registry->register(
                $product,
                new MappedProductPrincipalAdapter($product, $source['model'], app(LegacyIdentityResolver::class)),
            );
        }

        foreach ($this->products as $product => $source) {
            $path = '/_test/platform-principal/'.$product;

            Route::middleware(['auth:platform', 'platform.principal:'.$product])
                ->get($path, static fn (Request $request) => response()->json([
                    'principal_class' => get_class($request->user()),
                    'principal_id' => (string) $request->user()->getAuthIdentifier(),
                    'platform_class' => get_class($request->user('platform')),
                    'platform_id' => (string) $request->user('platform')->getAuthIdentifier(),
                ]));
        }
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        Schema::connection('core')->dropIfExists('legacy_identity_maps');
        Schema::connection('deployer')->dropIfExists('organization_user');
        Schema::connection('deployer')->dropIfExists('organizations');
        Schema::connection('monitor')->dropIfExists('user_workspace');
        Schema::connection('monitor')->dropIfExists('workspaces');

        foreach (array_unique(array_column($this->products, 'connection')) as $connection) {
            Schema::connection($connection)->dropIfExists('users');
        }

        parent::tearDown();
    }

    public function test_each_product_resolves_its_own_mapped_principal_and_keeps_core_identity_available(): void
    {
        $sourceId = 1201;

        foreach ($this->products as $product => $source) {
            DB::connection($source['connection'])->table('users')->insert([
                'id' => $sourceId,
                'name' => ucfirst($product).' account',
                'email' => $product.'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->map($product, $sourceId);
            $this->actingAs($this->platformUser, 'platform');

            $response = $this->get('/_test/platform-principal/'.$product);

            $this->assertSame(200, $response->status(), $product.': '.$response->getContent());
            $response
                ->assertJsonPath('principal_class', $source['model'])
                ->assertJsonPath('principal_id', (string) $sourceId)
                ->assertJsonPath('platform_class', PlatformUser::class)
                ->assertJsonPath('platform_id', $this->platformUser->getAuthIdentifier());

            $this->assertSame('platform', Auth::getDefaultDriver());
        }
    }

    public function test_a_missing_or_ambiguous_product_mapping_does_not_resolve_a_local_user(): void
    {
        DB::connection('deployer')->table('users')->insert([
            'id' => 42,
            'name' => 'Deployer account',
            'email' => 'deployer@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->platformUser, 'platform');

        $this->get('/_test/platform-principal/deployer')->assertForbidden();

        $this->map('deployer', 42);
        $this->map('deployer', 43);

        $this->get('/_test/platform-principal/deployer')->assertForbidden();
    }

    public function test_an_unmapped_product_account_cannot_borrow_another_products_mapping(): void
    {
        DB::connection('monitor')->table('users')->insert([
            'id' => 42,
            'name' => 'Monitor account',
            'email' => 'monitor@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->map('deployer', 42);
        $this->actingAs($this->platformUser, 'platform');

        $this->get('/_test/platform-principal/monitor')->assertForbidden();
    }

    public function test_core_authority_provisions_a_local_account_and_exact_identity_mapping_on_first_access(): void
    {
        config(['platform.products.analytics.auth_authority' => 'core']);
        $this->actingAs($this->platformUser, 'platform');

        $response = $this->get('/_test/platform-principal/analytics');

        $response->assertOk()
            ->assertJsonPath('principal_class', AnalyticsUser::class)
            ->assertJsonPath('platform_id', $this->platformUser->getAuthIdentifier());

        $productUser = AnalyticsUser::query()->where('platform_user_id', $this->platformUser->getKey())->sole();
        $this->assertSame($this->platformUser->email, $productUser->email);
        $this->assertTrue(Hash::check('platform account password', (string) $productUser->getAuthPassword()));
        $this->assertDatabaseHas('legacy_identity_maps', [
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $productUser->getKey(),
            'canonical_entity' => 'user',
            'canonical_id' => (string) $this->platformUser->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'shared-auth-provisioning',
        ], 'core');
    }

    public function test_core_authority_synchronizes_only_the_exact_reconciled_product_identity(): void
    {
        config(['platform.products.analytics.auth_authority' => 'core']);
        DB::connection('analytics')->table('users')->insert([
            'id' => 42,
            'platform_user_id' => $this->platformUser->getKey(),
            'name' => 'Old local name',
            'email' => 'old-local@example.test',
            'password' => Hash::make('old local password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->map('analytics', 42);
        $this->platformUser->forceFill([
            'name' => 'Updated Core name',
            'email' => 'updated-core@example.test',
            'password' => Hash::make('updated core password'),
            'email_verified_at' => now(),
        ]);
        $this->actingAs($this->platformUser, 'platform');

        $this->get('/_test/platform-principal/analytics')->assertOk();

        $productUser = AnalyticsUser::query()->findOrFail(42);
        $this->assertSame('Updated Core name', $productUser->name);
        $this->assertSame('updated-core@example.test', $productUser->email);
        $this->assertTrue(Hash::check('updated core password', (string) $productUser->getAuthPassword()));
        $this->assertNotNull($productUser->email_verified_at);
    }

    public function test_core_authority_does_not_merge_an_unmapped_product_account_by_email(): void
    {
        config(['platform.products.analytics.auth_authority' => 'core']);
        DB::connection('analytics')->table('users')->insert([
            'id' => 23,
            'name' => 'Existing local account',
            'email' => $this->platformUser->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->platformUser, 'platform');

        $this->get('/_test/platform-principal/analytics')->assertStatus(409);

        $this->assertSame(0, DB::connection('core')->table('legacy_identity_maps')->count());
        $this->assertDatabaseHas('users', [
            'id' => 23,
            'platform_user_id' => null,
            'email' => $this->platformUser->email,
        ], 'analytics');
    }

    public function test_core_authority_provisions_deployers_personal_workspace(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->actingAs($this->platformUser, 'platform');

        $response = $this->get('/_test/platform-principal/deployer');

        $response->assertOk()
            ->assertJsonPath('principal_class', DeployerUser::class)
            ->assertJsonPath('platform_id', $this->platformUser->getAuthIdentifier());

        $productUser = DeployerUser::query()->where('platform_user_id', $this->platformUser->getKey())->sole();
        $this->assertNotNull($productUser->current_organization_id);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $productUser->current_organization_id,
            'user_id' => $productUser->getKey(),
            'role' => 'owner',
        ], 'deployer');
    }

    public function test_core_authority_provisions_a_monitor_workspace_for_a_new_platform_account(): void
    {
        config(['platform.products.monitor.auth_authority' => 'core']);
        $this->actingAs($this->platformUser, 'platform');

        $response = $this->get('/_test/platform-principal/monitor');

        $response->assertOk()
            ->assertJsonPath('principal_class', MonitorUser::class)
            ->assertJsonPath('platform_id', $this->platformUser->getAuthIdentifier());

        $productUser = MonitorUser::query()->where('platform_user_id', $this->platformUser->getKey())->sole();
        $workspace = $productUser->workspaces()->sole();
        $this->assertSame($productUser->getKey(), $workspace->owner_id);
        $this->assertSame('owner', $workspace->pivot->role);
    }

    /** Insert one reconciled legacy identity mapping for this test account. */
    private function map(string $product, int $sourceId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => $product,
            'source_entity' => 'user',
            'source_id' => (string) $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => (string) $this->platformUser->getAuthIdentifier(),
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function registerProvisioners(): void
    {
        $registry = app(ProductPrincipalProvisionerRegistry::class);

        foreach ([
            'deployer' => DeployerPlatformPrincipalProvisioner::class,
            'monitor' => MonitorPlatformPrincipalProvisioner::class,
            'analytics' => AnalyticsPlatformPrincipalProvisioner::class,
        ] as $product => $provisioner) {
            if ($registry->get($product) === null) {
                $registry->register($product, app($provisioner));
            }
        }
    }
}
