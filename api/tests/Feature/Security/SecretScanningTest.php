<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SelectionKind;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\TelemetryEvent;
use App\Models\Website;
use App\Support\Security\SecretPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class SecretScanningTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check credentials are found in code and telemetry, stored redacted, and that the free plan doesn't include it.
     *
     * @return void
     */
    public function test_secrets_are_found_in_code_and_telemetry_and_never_stored_in_full(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['security', 'infrastructure', 'monitoring'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $website = Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $environment->id, 'name' => 'Shop']);
        $stripe = 'sk_live_'.str_repeat('a1B2', 6);
        $aws = 'AKIA'.str_repeat('Q', 16);
        $page = "/api/app/projects/{$project->id}/security";

        $this->actingAs($owner)->postJson("{$page}/scans", ['kind' => 'secrets'])->assertJsonValidationErrors('kind');

        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'pro'])->save();
        $this->shell->reply("./app/Services/Billing.php:12:{$stripe}\n./config/aws.php:3:{$aws}\n./.env.production:1:{$aws}\nENVFILE:./.env.production\n");
        TelemetryEvent::factory()->create(['environment_id' => $environment->id, 'type' => 'exception', 'name' => 'Payment failed', 'payload' => ['message' => "Bad key {$stripe}"], 'occurred_at' => now()->subHour()]);
        $this->actingAs($owner)->postJson("{$page}/scans", ['kind' => 'secrets'])->assertSuccessful();

        $this->assertStringContainsString('grep -IHnoE', $this->shell->ran[0]['command']);
        $this->assertStringContainsString("-path './vendor'", $this->shell->ran[0]['command']);
        $titles = SecurityFinding::query()->where('source', 'secrets')->orderBy('id')->pluck('severity', 'title')->all();
        $this->assertSame([
            'Stripe live secret key in app/Services/Billing.php' => 'critical',
            'AWS access key in config/aws.php' => 'critical',
            'An environment file (.env.production) is in the code' => 'high',
            'Stripe live secret key in exception data' => 'critical',
        ], $titles);
        $this->assertFalse(SecurityFinding::query()->where('detail', 'like', "%{$stripe}%")->orWhere('title', 'like', "%{$aws}%")->exists(), 'Never stored in full.');
        $this->assertStringContainsString('sk_l••••••••', (string) SecurityFinding::query()->where('scope', "code:{$website->id}")->where('title', 'like', 'Stripe%')->value('detail'));
        $this->actingAs($owner)->getJson("{$page}/findings?source=secrets")->assertOk()->assertJsonFragment(['title' => 'AWS access key in config/aws.php'])->assertDontSee($aws);
    }

    /**
     * Check the patterns and redaction.
     *
     * @return void
     */
    public function test_patterns_find_known_formats(): void
    {
        $found = SecretPatterns::find('token ghp_'.str_repeat('x', 36).' and -----BEGIN OPENSSH PRIVATE KEY----- and nothing else like sk_test_123');
        $this->assertSame(['private-key', 'github-token'], array_column($found, 'rule'));
        $this->assertSame('ghp_•••••••• (40 characters)', SecretPatterns::redact('ghp_'.str_repeat('x', 36)));
        $this->assertSame('slack-webhook', SecretPatterns::ruleFor('https://hooks.slack.com/services/T0001/B0001/abcdefghijklmnop'));
    }
}
