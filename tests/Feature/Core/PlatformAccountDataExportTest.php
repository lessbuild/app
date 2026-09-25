<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\WorkspaceFeedback;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformAccountDataExportTest extends TestCase
{
    public function test_signed_in_user_can_download_only_their_shared_account_data_without_credentials(): void
    {
        $this->get(route('platform.account.export'))
            ->assertRedirect(route('platform.login'));

        Artisan::call('platform:migrate', ['module' => 'core']);

        $user = PlatformUser::query()->create([
            'name' => 'Export Owner',
            'email' => 'export-owner@example.test',
            'email_normalized' => 'export-owner@example.test',
            'email_verified_at' => now(),
            'password' => bcrypt('not-for-export'),
            'password_set_at' => now(),
            'two_factor_secret' => encrypt('totp-secret-not-for-export'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-not-for-export'])),
            'two_factor_confirmed_at' => now(),
            'preferences' => ['theme' => 'dark'],
            'status' => 'active',
        ]);
        $otherUser = PlatformUser::query()->create([
            'name' => 'Other Member',
            'email' => 'other-member@example.test',
            'email_normalized' => 'other-member@example.test',
            'status' => 'active',
        ]);

        $workspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        $projectId = (string) Str::ulid();
        $now = now();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_user_id' => $user->getKey(),
            'name' => 'Export Workspace',
            'slug' => 'export-workspace',
            'status' => 'active',
            'settings' => json_encode(['private_setting' => 'workspace-secret-not-for-export']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $workspaceId,
            'user_id' => $user->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $membershipId,
            'product' => 'monitor',
            'role' => 'admin',
            'status' => 'active',
            'granted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'product' => 'monitor',
            'provider' => 'stripe',
            'provider_account_key' => 'default',
            'provider_subscription_id' => 'subscription-secret-not-for-export',
            'provider_price_id' => 'price-secret-not-for-export',
            'plan_key' => 'team',
            'status' => 'active',
            'quantity' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->getKey(),
            'name' => 'Export Project',
            'slug' => 'export-project',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'user_id' => $user->getKey(),
            'role' => 'admin',
            'status' => 'active',
            'granted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('user_identities')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->getKey(),
            'provider' => 'github',
            'provider_user_id' => 'github-user-123',
            'provider_email' => 'export-owner@github.example.test',
            'verified_at' => $now,
            'status' => 'active',
            'metadata' => json_encode(['access_token' => 'oauth-token-not-for-export']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('passkeys')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->getKey(),
            'name' => 'Personal laptop',
            'credential_id' => 'passkey-id-not-for-export',
            'credential' => json_encode(['private_key' => 'passkey-private-not-for-export']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('platform_auth_sessions')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->getKey(),
            'remember_token_hash' => 'remember-token-hash-not-for-export',
            'remembered' => true,
            'ip_address' => '192.0.2.1',
            'user_agent' => 'Example browser',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('workspace_membership_events')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'actor_user_id' => $user->getKey(),
            'subject_user_id' => $otherUser->getKey(),
            'event' => 'member_invited',
            'previous_role' => null,
            'new_role' => 'member',
            'metadata' => json_encode(['email' => 'other-member@example.test']),
            'created_at' => $now,
        ]);
        WorkspaceFeedback::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->getKey(),
            'product' => 'core',
            'category' => 'idea',
            'severity' => 'normal',
            'status' => 'open',
            'title' => 'My feedback',
            'description' => 'My submitted feedback text',
            'reproduction_steps' => 'My submitted steps',
            'page' => '/account/security',
        ]);

        $response = $this->actingAs($user, 'platform')
            ->get(route('platform.account.export'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith(
            'attachment; filename="buildpusher-account-export-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame((string) $user->getKey(), $response->json('account.id'));
        $this->assertSame(['theme' => 'dark'], $response->json('account.preferences'));
        $this->assertTrue($response->json('account.two_factor_enabled'));
        $this->assertSame('github-user-123', $response->json('linked_accounts.0.provider_user_id'));
        $this->assertSame('Export Workspace', $response->json('workspace_memberships.0.workspace_name'));
        $this->assertSame('team', $response->json('owned_workspace_subscriptions.0.plan_key'));
        $this->assertSame('Export Project', $response->json('project_memberships.0.project_name'));
        $this->assertSame('My submitted feedback text', $response->json('submitted_feedback.0.description'));
        $this->assertTrue($response->json('workspace_access_history.0.performed_by_account'));

        $serialized = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach ([
            'not-for-export',
            'oauth-token-not-for-export',
            'workspace-secret-not-for-export',
            'other-member@example.test',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }
}
