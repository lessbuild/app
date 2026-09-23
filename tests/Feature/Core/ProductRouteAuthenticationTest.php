<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProductPrincipalAdapter;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ProductRouteAuthenticationTest extends TestCase
{
    private PlatformUser $platformUser;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.products.analytics.auth_authority' => 'core',
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
            'lessbuild.trusted_hosts' => ['analytics.example.test'],
        ]);

        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
        });

        Schema::connection('analytics')->create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        $this->platformUser = (new PlatformUser)->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Platform account',
            'email' => 'platform@example.test',
            'status' => 'active',
        ]);

        app(ProductPrincipalRegistry::class)->register(
            'analytics',
            new MappedProductPrincipalAdapter('analytics', AnalyticsUser::class, app(LegacyIdentityResolver::class)),
        );

        $authentication = app(ProductAuthentication::class);
        Route::middleware($authentication->authenticatedMiddleware('analytics'))
            ->get('/_test/product-authentication/analytics', static fn (Request $request) => response()->json([
                'principal_class' => get_class($request->user()),
                'principal_id' => (string) $request->user()->getAuthIdentifier(),
                'platform_id' => (string) $request->user('platform')->getAuthIdentifier(),
            ]));
        Route::middleware($authentication->guestMiddleware('analytics'))
            ->get('/_test/product-guest/analytics', static fn () => response('legacy guest form'));
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        Schema::connection('core')->dropIfExists('legacy_identity_maps');
        Schema::connection('analytics')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_legacy_authority_keeps_the_existing_laravel_auth_middleware(): void
    {
        config(['platform.products.analytics.auth_authority' => 'legacy']);

        $this->assertSame(['auth'], app(ProductAuthentication::class)->authenticatedMiddleware('analytics'));
        $this->assertSame(['guest'], app(ProductAuthentication::class)->guestMiddleware('analytics'));
    }

    public function test_unknown_authority_values_fail_instead_of_silently_falling_back_to_legacy(): void
    {
        config(['platform.products.analytics.auth_authority' => 'cor']);

        $this->expectException(\InvalidArgumentException::class);
        app(ProductAuthentication::class)->usesCoreAuthority('analytics');
    }

    public function test_core_authority_resolves_the_product_principal_for_an_authenticated_route(): void
    {
        DB::connection('analytics')->table('users')->insert([
            'id' => 1402,
            'name' => 'Analytics account',
            'email' => 'analytics@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mapAnalyticsUser(1402);
        $this->actingAs($this->platformUser, 'platform');

        $this->get('/_test/product-authentication/analytics')
            ->assertOk()
            ->assertJsonPath('principal_class', AnalyticsUser::class)
            ->assertJsonPath('principal_id', '1402')
            ->assertJsonPath('platform_id', $this->platformUser->getAuthIdentifier());
    }

    public function test_core_product_guest_routes_redirect_to_central_login_with_a_product_return_target(): void
    {
        $response = $this->get('http://analytics.example.test/_test/product-guest/analytics');

        $this->assertProductLoginRedirect($response, 'https://analytics.example.test/_test/product-guest/analytics');
    }

    public function test_unauthenticated_core_product_routes_redirect_to_central_login_with_a_product_return_target(): void
    {
        $request = Request::create('http://analytics.example.test/_test/product-authentication/analytics');

        $this->assertSame('analytics', app(ProductAuthentication::class)->productForRequest($request));

        $response = $this->get('http://analytics.example.test/_test/product-authentication/analytics');

        $this->assertProductLoginRedirect($response, 'https://analytics.example.test/_test/product-authentication/analytics');
    }

    public function test_core_authenticated_users_without_a_reconciled_product_identity_cannot_enter_the_product(): void
    {
        $this->actingAs($this->platformUser, 'platform');

        $this->get('/_test/product-authentication/analytics')->assertForbidden();
        $this->get('/_test/product-guest/analytics')->assertForbidden();
    }

    private function mapAnalyticsUser(int $sourceId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $sourceId,
            'canonical_entity' => 'user',
            'canonical_id' => (string) $this->platformUser->getAuthIdentifier(),
            'status' => 'reconciled',
        ]);
    }

    private function assertProductLoginRedirect(TestResponse $response, string $returnTo): void
    {
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertSame('analytics.example.test', parse_url($location, PHP_URL_HOST));
        $this->assertSame('/platform/login', parse_url($location, PHP_URL_PATH));

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame($returnTo, $query['return_to'] ?? null);
    }
}
