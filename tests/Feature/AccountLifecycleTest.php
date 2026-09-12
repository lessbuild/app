<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_and_terms_are_public_and_linked_from_the_homepage(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Information we process')
            ->assertSee(config('legal.contact_email'));
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Acceptable use');
        $this->get('/')
            ->assertSee(route('privacy'))
            ->assertSee(route('terms'));
    }

    public function test_account_export_is_private_and_excludes_credentials(): void
    {
        $this->get(route('account.export'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $user->providers()->create([
            'name' => 'Production cloud',
            'description' => 'Primary account',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'never-export-this-token',
        ]);

        $response = $this->actingAs($user)->get(route('account.export'))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('content-type', 'application/json')
            ->assertHeader('content-disposition')
            ->assertJsonPath('account.email', $user->email)
            ->assertJsonPath('current_workspace_data.providers.0.name', 'Production cloud');

        $this->assertStringNotContainsString('never-export-this-token', $response->getContent());
        $this->assertStringContainsString('excluded for safety', $response->json('note'));
    }

    public function test_account_deletion_requires_exact_confirmation_and_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('account.destroy'), [
            'confirmation' => $user->email,
            'current_password' => 'wrong-password',
        ])->assertSessionHasErrorsIn('deleteAccount', 'current_password');
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->actingAs($user)->delete(route('account.destroy'), [
            'confirmation' => $user->email,
            'current_password' => 'password',
        ])->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('organizations', ['owner_id' => $user->id]);
    }

    public function test_account_deletion_refuses_to_remove_a_teammates_workspace(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $owner->currentOrganization->members()->syncWithoutDetaching([$member->id => ['role' => 'viewer']]);

        $this->actingAs($owner)->delete(route('account.destroy'), [
            'confirmation' => $owner->email,
            'current_password' => 'password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('organizations', ['id' => $owner->current_organization_id]);
    }

    public function test_account_deletion_refuses_a_shared_workspace_membership_before_deleting_anything(): void
    {
        $user = User::factory()->create();
        $workspaceOwner = User::factory()->create();
        $sharedWorkspace = $workspaceOwner->currentOrganization;
        $sharedWorkspace->members()->attach($user, ['role' => 'viewer']);

        $this->actingAs($user)->delete(route('account.destroy'), [
            'confirmation' => $user->email,
            'current_password' => 'password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('organizations', ['id' => $user->current_organization_id]);
        $this->assertDatabaseHas('organizations', ['id' => $sharedWorkspace->id]);
    }

    public function test_account_deletion_requires_two_factor_and_consumes_a_recovery_code_only_after_validation(): void
    {
        $user = User::factory()->create();
        $service = app(TwoFactorAuthentication::class);
        $recoveryCode = 'ABCD-EFAB-CDEF';
        $user->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => $service->recoveryCodeHashes([$recoveryCode]),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)->delete(route('account.destroy'), [
            'confirmation' => $user->email,
            'current_password' => 'password',
            'code' => 'invalid-code',
        ])->assertSessionHasErrors(['code'], errorBag: 'deleteAccount');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertCount(1, $user->fresh()->two_factor_recovery_codes);

        $this->actingAs($user)->delete(route('account.destroy'), [
            'confirmation' => $user->email,
            'current_password' => 'password',
            'code' => $recoveryCode,
        ])->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_account_deletion_refuses_active_commands_without_deleting_the_account(): void
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => 'active',
        ]);
        $execution = $server->commandExecutions()->create([
            'user_id' => $user->id,
            'command' => 'uptime',
            'status' => ServerCommandExecution::STATUS_RUNNING,
        ]);

        $this->actingAs($user)->delete(route('account.destroy'), [
            'confirmation' => $user->email,
            'current_password' => 'password',
        ])->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('organizations', ['id' => $user->current_organization_id]);
        $this->assertModelExists($execution);
    }
}
