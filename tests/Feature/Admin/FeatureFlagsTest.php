<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\FeatureFlag;
use App\Models\PlatformAdminEvent;
use App\Models\RepositoryWebhookDelivery;
use App\Models\User;
use App\Services\Admin\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flags_start_off_and_turn_on_for_everyone_or_chosen_accounts(): void
    {
        $admin = $this->admin();
        [$chosen, $other] = [Account::factory()->create(), Account::factory()->create()];
        $flags = fn (): FeatureFlags => app(FeatureFlags::class);
        $this->assertFalse($flags()->enabled('deploy.new-scheduler', $chosen));

        $this->as($admin)->post('/admin/flags', ['key' => 'Deploy New', 'description' => 'x'])->assertSessionHasErrors('key');
        $this->as($admin)->post('/admin/flags', ['key' => 'deploy.new-scheduler', 'description' => 'The new scheduler'])->assertRedirect('/admin/flags');
        $flag = FeatureFlag::query()->sole();
        $this->assertSame('off', $flag->state);

        $this->as($admin)->put("/admin/flags/{$flag->id}", ['state' => 'accounts', 'description' => 'The new scheduler', 'account_ids' => "nope\n"])->assertSessionHasErrors('account_ids');
        $this->as($admin)->put("/admin/flags/{$flag->id}", ['state' => 'accounts', 'description' => 'The new scheduler', 'account_ids' => "{$chosen->id}\n{$chosen->id}"])->assertRedirect();
        $this->forgetFlags();
        $this->assertSame([true, false, false], [$flags()->enabled('deploy.new-scheduler', $chosen), $flags()->enabled('deploy.new-scheduler', $other), $flags()->enabled('deploy.new-scheduler')]);

        $this->as($admin)->put("/admin/flags/{$flag->id}", ['state' => 'on', 'description' => 'The new scheduler'])->assertRedirect();
        $this->forgetFlags();
        $this->assertTrue($flags()->enabled('deploy.new-scheduler', $other));
        $this->as($admin)->get('/admin/flags')->assertOk()->assertSee('deploy.new-scheduler')->assertSee('The new scheduler');

        $this->as($admin)->delete("/admin/flags/{$flag->id}")->assertRedirect();
        $this->forgetFlags();
        $this->assertFalse($flags()->enabled('deploy.new-scheduler', $other));
        $this->assertSame(['flag.created', 'flag.changed', 'flag.changed', 'flag.deleted'], PlatformAdminEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_old_deliveries_trail_entries_and_read_notifications_are_deleted(): void
    {
        $admin = $this->admin();
        $delivery = new RepositoryWebhookDelivery;
        $delivery->forceFill(['repository_id' => \App\Models\Repository::factory()->create()->id, 'delivery_id' => 'old', 'status' => 'queued'])->save();
        RepositoryWebhookDelivery::query()->whereKey($delivery->id)->update(['created_at' => now()->subDays(31)]);
        foreach ([['read_at' => now()->subDays(91)], ['read_at' => now()->subDays(10)], ['read_at' => null]] as $row) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $admin->id, 'data' => '{}', 'created_at' => now()->subDays(100), 'updated_at' => now(), ...$row]);
        }

        Artisan::call('model:prune', ['--model' => [RepositoryWebhookDelivery::class]]);
        Artisan::call('notifications:prune');
        $this->assertSame(0, RepositoryWebhookDelivery::query()->count());
        $this->assertSame(2, DB::table('notifications')->count());
        $this->as($admin)->get('/admin/health')->assertSee('Repository webhook deliveries')->assertSee('notifications:prune');
    }

    /**
     * Forget flags read in earlier requests of this test, as a new request would.
     *
     * @return void
     */
    private function forgetFlags(): void
    {
        app(FeatureFlags::class)->flush();
    }

    /**
     * Make a platform admin with an authenticator app.
     *
     * @return User
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();
        $user->forceFill(['is_platform_admin' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    /**
     * Act as the admin with a fresh confirmation.
     *
     * @param  User  $admin
     * @return $this
     */
    private function as(User $admin): static
    {
        return $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }
}
