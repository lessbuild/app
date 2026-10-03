<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Actions\Audit\RecordAuditEntry;
use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\AuditStream;
use App\Models\BackupDestination;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AuditStreamTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that audit entries are streamed to Slack, a signed webhook and S3 as they're recorded, and that a stream
     * failing 20 times in a row is paused with its error shown.
     *
     * @return void
     */
    public function test_audit_entries_are_streamed_as_they_happen(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        $project = Project::factory()->create();
        $owner = $this->ownerOf($project);
        $owner->forceFill(['current_account_id' => $project->account_id])->save();
        $destination = BackupDestination::factory()->create(['account_id' => $project->account_id, 'endpoint' => 'https://s3.eu-central-1.amazonaws.com', 'region' => 'eu-central-1', 'bucket' => 'audit-bucket', 'path_prefix' => 'acme']);
        $siemStatus = 200;
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok'),
            'siem.example.com/*' => function () use (&$siemStatus) {
                return Http::response('', $siemStatus);
            },
            's3.eu-central-1.amazonaws.com/*' => Http::response('', 200),
        ]);

        $this->actingAs($owner)->getJson('/api/app/account/audit-log')->assertOk()->assertJsonPath('canManageStreams', true);
        $this->actingAs($owner)->postJson('/api/app/account/audit-log/streams', ['name' => 'Bad', 'type' => 'webhook', 'endpoint_url' => 'http://siem.example.com'])->assertJsonValidationErrors('endpoint_url');
        $this->actingAs($owner)->postJson('/api/app/account/audit-log/streams', ['name' => 'Chat', 'type' => 'slack', 'endpoint_url' => 'https://hooks.slack.com/services/T0/B0/abc'])->assertCreated()->assertJsonPath('redirect', '/account/audit-log');
        $this->assertNotEmpty($this->actingAs($owner)->postJson('/api/app/account/audit-log/streams', ['name' => 'SIEM', 'type' => 'webhook', 'endpoint_url' => 'https://siem.example.com/ingest'])->assertCreated()->json('secret'));
        $this->actingAs($owner)->postJson('/api/app/account/audit-log/streams', ['name' => 'Archive', 'type' => 's3', 'backup_destination_id' => $destination->id])->assertCreated();
        $siem = AuditStream::query()->where('name', 'SIEM')->sole();

        app(RecordAuditEntry::class)->handle(AuditAction::ProjectUpdated, $owner, $project->account_id, [], $project->id);
        $entry = AuditEntry::query()->where('action', AuditAction::ProjectUpdated)->latest('created_at')->firstOrFail();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://hooks.slack.com/') && str_contains((string) $request['text'], "*{$owner->name}*"));
        Http::assertSent(function (Request $request) use ($siem, $entry): bool {
            if ($request->url() !== 'https://siem.example.com/ingest') {
                return false;
            }
            $signature = 'v1='.hash_hmac('sha256', $request->header('X-BuildPusher-Timestamp')[0].'.'.$request->body(), (string) $siem->signing_secret);

            return $request['id'] === $entry->id && $request['action'] === 'project.updated' && $request->header('X-BuildPusher-Signature')[0] === $signature;
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), 'audit-bucket') && str_ends_with($request->url(), "acme/audit/{$project->account_id}/".$entry->created_at->utc()->format('Y/m/d')."/{$entry->id}.json"));
        $this->assertNotNull($siem->refresh()->last_delivered_at);

        $siemStatus = 500;
        for ($i = 0; $i < 20; $i++) {
            app(RecordAuditEntry::class)->handle(AuditAction::ProjectUpdated, $owner, $project->account_id, [], $project->id);
        }
        $siem->refresh();
        $this->assertFalse($siem->enabled);
        $this->assertStringContainsString('HTTP 500', (string) $siem->last_error);
        $streams = (array) $this->actingAs($owner)->getJson('/api/app/account/audit-log')->assertOk()->json('streams');
        $paused = array_values(array_filter($streams, fn (mixed $stream): bool => is_array($stream) && $stream['name'] === 'SIEM'))[0] ?? [];
        $this->assertFalse($paused['enabled'] ?? true);
        $this->assertStringContainsString('HTTP 500', (string) ($paused['lastError'] ?? ''));
    }
}
