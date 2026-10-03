<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Notifications\InfrastructureBudgetReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class InfrastructureBudgetAlertsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that owners hear once when servers reach 80% of the budget and once more at 100%, and that EUR-priced
     * servers and accounts without a budget don't count.
     *
     * @return void
     */
    public function test_owners_hear_once_per_threshold_each_month(): void
    {
        Notification::fake();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $project->account->forceFill(['monthly_infrastructure_budget' => 100])->save();
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $server = fn (float $cost, string $currency = 'USD') => Server::factory()->create(['provider_id' => $provider->id, 'monthly_cost' => $cost, 'monthly_cost_currency' => $currency]);
        $server(50);
        $server(500, 'EUR');
        Project::factory()->create();

        $this->command('infrastructure:budget-alerts')->expectsOutput('Sent 0 infrastructure budget alerts.');
        $server(35);
        $this->command('infrastructure:budget-alerts')->expectsOutput('Sent 1 infrastructure budget alerts.');
        $this->command('infrastructure:budget-alerts')->expectsOutput('Sent 0 infrastructure budget alerts.');
        Notification::assertSentTo($owner, InfrastructureBudgetReached::class, fn (InfrastructureBudgetReached $notification): bool => str_contains($notification->toArray($owner)['title'], 'at 80%'));

        $server(20);
        $this->command('infrastructure:budget-alerts')->expectsOutput('Sent 1 infrastructure budget alerts.');
        Notification::assertSentTo($owner, InfrastructureBudgetReached::class, fn (InfrastructureBudgetReached $notification): bool => str_contains($notification->toArray($owner)['title'], 'over their monthly budget')
            && str_contains($notification->toArray($owner)['body'], '$105.00 a month against a budget of $100.00')
            && str_ends_with($notification->toArray($owner)['url'], '/infrastructure/costs'));
        Notification::assertSentToTimes($owner, InfrastructureBudgetReached::class, 2);
    }
}
