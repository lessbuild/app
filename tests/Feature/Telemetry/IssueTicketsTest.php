<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\Issue;
use App\Models\IssueTracker;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class IssueTicketsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check trackers are connected with their credentials encrypted and never shown, bad settings are refused, and a
     * ticket is filed in GitHub, Linear and Jira with the issue's details, linked on the issue, only once, with the
     * tracker's own error when it refuses.
     *
     * @return void
     */
    public function test_tickets_are_filed_in_github_linear_and_jira(): void
    {
        Http::fake([
            'api.github.com/repos/acme/shop/issues' => Http::response(['number' => 42, 'html_url' => 'https://github.com/acme/shop/issues/42'], 201),
            'api.linear.app/graphql' => Http::response(['data' => ['issueCreate' => ['success' => true, 'issue' => ['identifier' => 'ENG-7', 'url' => 'https://linear.app/acme/issue/ENG-7']]]]),
            'acme.atlassian.net/rest/api/3/issue' => Http::sequence()->push(['errors' => ['issuetype' => 'Specify a valid issue type']], 400)->push(['key' => 'OPS-3'], 201),
        ]);
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $base = "/projects/{$project->id}/monitoring";

        $this->actingAs($owner)->post("{$base}/trackers", ['kind' => 'github', 'repository' => 'not a repo', 'token' => 'x'])->assertSessionHasErrors('repository');
        $this->actingAs($owner)->post("{$base}/trackers", ['kind' => 'jira', 'site' => 'https://evil.example', 'email' => 'a@b.c', 'token' => 't', 'project_key' => 'OPS'])->assertSessionHasErrors('site');
        $this->actingAs($owner)->post("{$base}/trackers", ['kind' => 'linear', 'api_key' => 'lin_api_1'])->assertSessionHasErrors('kind');
        $this->actingAs($owner)->post("{$base}/trackers", ['kind' => 'github', 'name' => 'Shop repo', 'repository' => 'acme/shop', 'token' => 'github_pat_secret'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/trackers", ['kind' => 'linear', 'name' => 'Linear', 'api_key' => 'lin_api_secret', 'team_id' => 'team-1'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/trackers", ['kind' => 'jira', 'name' => 'Jira', 'site' => 'https://acme.atlassian.net/', 'email' => 'ops@acme.test', 'token' => 'jira-secret', 'project_key' => 'ops'])->assertRedirect();
        [$github, $linear, $jira] = [IssueTracker::query()->where('kind', 'github')->sole(), IssueTracker::query()->where('kind', 'linear')->sole(), IssueTracker::query()->where('kind', 'jira')->sole()];
        $this->assertStringNotContainsString('github_pat_secret', (string) $github->getRawOriginal('settings'), 'Credentials are encrypted.');
        $this->assertSame('OPS', $jira->settings['project_key']);
        $this->actingAs($owner)->get("{$base}/setup")->assertOk()->assertSee('Shop repo')->assertSee('acme/shop')->assertDontSee('github_pat_secret')->assertDontSee('lin_api_secret');

        $first = Issue::factory()->for($project)->create(['title' => 'Timeout talking to the bank', 'location' => 'app/Payments.php:42', 'occurrences' => 12]);
        $this->actingAs($owner)->get("{$base}/issues/{$first->id}")->assertOk()->assertSee(__('Create ticket'));
        $this->actingAs($owner)->post("{$base}/issues/{$first->id}/ticket", ['tracker' => $github->id])->assertRedirect();
        $first->refresh();
        $this->assertSame(['#42', 'https://github.com/acme/shop/issues/42'], [$first->ticket_key, $first->ticket_url]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/shop/issues' && $request->hasHeader('Authorization', 'Bearer github_pat_secret')
            && $request['title'] === 'Timeout talking to the bank' && str_contains((string) $request['body'], 'app/Payments.php:42') && str_contains((string) $request['body'], '12 occurrences'));
        $this->actingAs($owner)->post("{$base}/issues/{$first->id}/ticket", ['tracker' => $linear->id])->assertSessionHasErrors('tracker');
        $this->actingAs($owner)->get("{$base}/issues/{$first->id}")->assertOk()->assertSee('#42')->assertSee(__('Ticket created'))->assertDontSee(__('Create ticket'));

        $second = Issue::factory()->for($project)->create();
        $this->actingAs($owner)->post("{$base}/issues/{$second->id}/ticket", ['tracker' => $linear->id])->assertRedirect();
        $this->assertSame('ENG-7', $second->refresh()->ticket_key);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.linear.app/graphql' && $request->hasHeader('Authorization', 'lin_api_secret') && $request['variables']['input']['teamId'] === 'team-1');

        $third = Issue::factory()->for($project)->create();
        $this->actingAs($owner)->post("{$base}/issues/{$third->id}/ticket", ['tracker' => $jira->id])->assertSessionHasErrors(['tracker' => 'The ticket wasn’t created. Jira answered HTTP 400: Specify a valid issue type']);
        $this->actingAs($owner)->post("{$base}/issues/{$third->id}/ticket", ['tracker' => $jira->id])->assertRedirect();
        $this->assertSame(['OPS-3', 'https://acme.atlassian.net/browse/OPS-3'], [$third->refresh()->ticket_key, $third->ticket_url]);

    }
}
