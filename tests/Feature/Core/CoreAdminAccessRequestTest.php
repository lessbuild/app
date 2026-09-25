<?php

namespace Tests\Feature\Core;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Modules\Deployer\Models\AccessRequest;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoreAdminAccessRequestTest extends TestCase
{
    private PlatformUser $platformUser;

    private DeployerUser $deployerUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.products.deployer.auth_authority' => 'legacy']);

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

        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
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

        Schema::connection('deployer')->create('access_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('email_hash', 64)->unique();
            $table->text('email');
            $table->text('name');
            $table->text('company')->nullable();
            $table->string('team_size', 20)->nullable();
            $table->string('plan', 30)->nullable();
            $table->text('use_case');
            $table->string('status', 20)->default('pending');
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        $this->platformUser = (new PlatformUser)->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Platform administrator',
            'email' => 'admin@example.test',
            'password' => Hash::make('a secure platform password'),
            'password_set_at' => now(),
            'status' => 'active',
        ]);

        $this->deployerUser = DeployerUser::query()->create([
            'id' => 641,
            'platform_user_id' => $this->platformUser->getKey(),
            'name' => 'Deployer administrator',
            'email' => 'admin@example.test',
            'password' => Hash::make('a secure deployer password'),
        ]);

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => (string) $this->deployerUser->getKey(),
            'canonical_entity' => 'user',
            'canonical_id' => (string) $this->platformUser->getKey(),
            'status' => 'reconciled',
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        Schema::connection('deployer')->dropIfExists('access_requests');
        Schema::connection('deployer')->dropIfExists('users');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');

        parent::tearDown();
    }

    public function test_core_platform_admin_can_review_existing_deployer_requests_without_moving_the_records(): void
    {
        config(['lessbuild.platform_admin_emails' => ['admin@example.test']]);
        $this->actingAs($this->platformUser, 'platform');
        $request = AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'applicant@example.test'),
            'email' => 'applicant@example.test',
            'name' => 'Buildpusher applicant',
            'company' => 'Example Studio',
            'use_case' => 'Deploy several production applications safely.',
        ]);

        $this->get(route('core.admin.access-requests.index', ['dialog' => 'review-access-request-'.$request->id]))
            ->assertOk()
            ->assertSee('data-product="core"', false)
            ->assertSee('Deployer access requests')
            ->assertSee('Buildpusher applicant')
            ->assertSee(route('core.admin.access-requests.export'), false)
            ->assertSee(route('core.admin.access-requests.update', $request), false)
            ->assertSee('data-modal-initial-open="true"', false);

        $this->patch(route('core.admin.access-requests.update', $request), [
            'status' => 'contacted',
            'review_notes' => 'Contacted from Core administration.',
        ])->assertRedirect();

        $this->assertDatabaseHas('access_requests', [
            'id' => $request->id,
            'status' => 'contacted',
            'reviewed_by' => $this->deployerUser->getKey(),
        ], 'deployer');
        $this->assertSame('Contacted from Core administration.', $request->refresh()->review_notes);
        $this->assertTrue(Route::has('admin.access-requests.index'));
        $this->assertSame(1, AccessRequest::query()->count());
    }

    public function test_core_access_request_admin_keeps_the_exact_product_identity_and_admin_checks(): void
    {
        $request = AccessRequest::query()->create([
            'email_hash' => hash('sha256', 'applicant@example.test'),
            'email' => 'applicant@example.test',
            'name' => 'Private applicant',
            'use_case' => 'Deploy production applications safely.',
        ]);

        config(['lessbuild.platform_admin_emails' => ['admin@example.test']]);
        $this->actingAs($this->platformUser, 'platform');
        $export = $this->get(route('core.admin.access-requests.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Private applicant', $export->streamedContent());

        config(['lessbuild.platform_admin_emails' => ['different@example.test']]);
        $this->get(route('core.admin.access-requests.index'))->assertForbidden();
        $this->patch(route('core.admin.access-requests.update', $request), ['status' => 'contacted'])
            ->assertForbidden();
    }

    public function test_core_admin_does_not_link_an_existing_deployer_user_by_email(): void
    {
        $unmappedPlatformUser = (new PlatformUser)->forceFill([
            'id' => (string) Str::ulid(),
            'name' => 'Unmapped account',
            'email' => 'admin@example.test',
            'status' => 'active',
        ]);
        $this->actingAs($unmappedPlatformUser, 'platform');

        $this->get(route('core.admin.access-requests.index'))->assertForbidden();
        $this->assertSame(1, DeployerUser::query()->count());
    }
}
