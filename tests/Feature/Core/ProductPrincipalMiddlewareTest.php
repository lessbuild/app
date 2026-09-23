<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\MappedProductPrincipalAdapter;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Monitor\Models\User as MonitorUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
        });

        foreach (array_unique(array_column($this->products, 'connection')) as $connection) {
            Schema::connection($connection)->create('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('name');
                $table->string('email');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->timestamps();
            });
        }

        $this->platformUser = (new PlatformUser)->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Platform account',
            'email' => 'platform@example.test',
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

    /** Insert one reconciled legacy identity mapping for this test account. */
    private function map(string $product, int $sourceId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'source_product' => $product,
            'source_entity' => 'user',
            'source_id' => (string) $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => (string) $this->platformUser->getAuthIdentifier(),
            'status' => 'reconciled',
        ]);
    }
}
