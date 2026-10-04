<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\FeatureFlags\Pages\ManageFeatureFlags;
use App\Models\Account;
use App\Models\FeatureFlag;
use App\Models\PlatformAdminEvent;
use App\Models\RepositoryWebhookDelivery;
use App\Models\User;
use App\Services\Admin\FeatureFlags;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FeatureFlagsTest extends TestCase
{
    use AdminHelpers;
    use RefreshDatabase;

    public function test_flags_start_off_and_turn_on_for_everyone_or_chosen_accounts(): void
    {
        $admin = $this->admin();
        [$chosen, $other] = [Account::factory()->create(), Account::factory()->create()];
        $flags = fn (): FeatureFlags => app(FeatureFlags::class);
        $this->assertFalse($flags()->enabled('deploy.new-scheduler', $chosen));
        $this->as($admin)->get('/admin/feature-flags')->assertOk()->assertSee(__('New flag'));

        Livewire::test(ManageFeatureFlags::class)->callAction('create', ['key' => 'Deploy New', 'description' => 'x'])->assertHasActionErrors(['key']);
        Livewire::test(ManageFeatureFlags::class)->callAction('create', ['key' => 'deploy.new-scheduler', 'description' => 'The new scheduler'])->assertHasNoActionErrors();
        $flag = FeatureFlag::query()->sole();
        $this->assertSame('off', $flag->state);

        $edit = fn (array $data) => Livewire::test(ManageFeatureFlags::class)->callAction(TestAction::make('edit')->table($flag), ['state' => 'accounts', 'description' => 'The new scheduler', ...$data]);
        $edit(['account_ids' => "nope\n"])->assertHasActionErrors(['account_ids']);
        $edit(['account_ids' => "{$chosen->id}\n{$chosen->id}"])->assertHasNoActionErrors();
        $this->forgetFlags();
        $this->assertSame([true, false, false], [$flags()->enabled('deploy.new-scheduler', $chosen), $flags()->enabled('deploy.new-scheduler', $other), $flags()->enabled('deploy.new-scheduler')]);

        $edit(['state' => 'on'])->assertHasNoActionErrors();
        $this->forgetFlags();
        $this->assertTrue($flags()->enabled('deploy.new-scheduler', $other));
        Livewire::test(ManageFeatureFlags::class)->assertCanSeeTableRecords([$flag->refresh()])->assertSee('The new scheduler');

        Livewire::test(ManageFeatureFlags::class)->callAction(TestAction::make('delete')->table($flag));
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
}
