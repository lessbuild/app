<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Deployer\Services\Core\DeployerNativeNotificationProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeployerNativeNotificationProviderTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private int $sourceUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchemas();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->sourceUserId = (int) DB::connection('deployer')->table('users')->insertGetId([
            'name' => 'Source user',
            'email' => 'source@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'name' => 'Core workspace',
            'slug' => 'core-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => (string) $this->sourceUserId,
            'canonical_entity' => 'user',
            'canonical_id' => $this->userId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['workspace_memberships', 'workspaces', 'legacy_identity_maps'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
        Schema::connection('deployer')->dropIfExists('notifications');
        Schema::connection('deployer')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_only_exact_recipient_security_notifications_project_without_a_deployer_grant(): void
    {
        $this->notification($this->sourceUserId, 'AccountSecurityNotification', [
            'category' => 'account',
            'resource_id' => $this->sourceUserId,
            'title' => '<b>Account security changed</b>',
            'message' => 'New sign-in recorded.',
            'status' => 'info',
        ], legacyTypes: true);
        $otherUserId = (int) DB::connection('deployer')->table('users')->insertGetId([
            'name' => 'Other user', 'email' => 'other@example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->notification($otherUserId, 'AccountSecurityNotification', [
            'category' => 'account', 'resource_id' => $otherUserId, 'title' => 'Foreign alert', 'message' => 'Private', 'status' => 'info',
        ]);
        $this->notification($this->sourceUserId, 'OtherNotification', [
            'category' => 'account', 'resource_id' => $this->sourceUserId, 'title' => 'Generic account row', 'message' => 'Not security', 'status' => 'info',
        ]);

        config(['platform.products.deployer.url' => 'https://deployer.example.test']);
        $user = new PlatformUser;
        $user->forceFill(['id' => $this->userId]);
        $user->exists = true;
        $workspace = new Workspace;
        $workspace->forceFill(['id' => $this->workspaceId, 'name' => 'Core workspace', 'status' => 'active']);
        $workspace->exists = true;

        $provider = app(DeployerNativeNotificationProvider::class);
        $feed = $provider->forWorkspace($user, $workspace, collect(), [], 100);

        $this->assertCount(1, $feed->notifications);
        $notification = $feed->notifications->sole();
        $this->assertTrue($notification->security);
        $this->assertNull($notification->projectId);
        $this->assertSame('Account security changed', $notification->title);
        $this->assertSame('https://deployer.example.test/account', $notification->resultUrl);
        $this->assertTrue($provider->setRead($user, $workspace, collect(), [], $notification->sourceReference, true));
        $this->assertNotNull(DB::connection('deployer')->table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', $this->sourceUserId)
            ->value('read_at'));
    }

    private function notification(int $recipientId, string $type, array $data, bool $legacyTypes = false): void
    {
        DB::connection('deployer')->table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => $legacyTypes ? 'App\\Notifications\\'.$type : 'App\\Modules\\Deployer\\Notifications\\'.$type,
            'notifiable_type' => $legacyTypes ? 'App\\Models\\User' : DeployerUser::class,
            'notifiable_id' => $recipientId,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchemas(): void
    {
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}
