<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SelectionKind;
use App\Models\AuditEntry;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\SecurityFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class ComplianceTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check the evidence pack downloads on the Team plan with its files and README, neutralises spreadsheet formulas,
     * and is recorded in the audit log.
     *
     * @return void
     */
    public function test_the_evidence_pack_downloads(): void
    {
        $project = Project::factory()->withServices(['security'])->create(['name' => 'Storefront']);
        $owner = $this->ownerOf($project);
        (new SecurityFinding)->forceFill(['project_id' => $project->id, 'source' => 'domains', 'scope' => 'domain:x', 'fingerprint' => 'f', 'severity' => 'high', 'title' => '=HYPERLINK("evil")', 'status' => 'resolved', 'first_seen_at' => now()->subMonth(), 'last_seen_at' => now()->subWeek(), 'resolved_at' => now()->subWeek()])->save();
        $page = "/projects/{$project->id}/security/compliance";
        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($owner)->get($page)->assertOk()->assertSee(__('Compliance reports come with the Team Security plan.'));
        $this->actingAs($owner)->withSession($session)->post("{$page}/evidence", ['months' => 12])->assertSessionHasErrors('months');

        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'team'])->save();
        $response = $this->actingAs($owner)->withSession($session)->post("{$page}/evidence", ['months' => 12])->assertOk()->assertDownload();
        $zip = new ZipArchive;
        $download = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $download);
        $this->assertTrue($zip->open($download->getFile()->getPathname()));
        $this->assertStringContainsString('# Security evidence: Storefront', (string) $zip->getFromName('README.md'));
        $this->assertStringContainsString('SOC 2 CC8.1', (string) $zip->getFromName('README.md'));
        $this->assertStringContainsString("'=HYPERLINK", (string) $zip->getFromName('vulnerabilities.csv'), 'Formulas are neutralised.');
        $this->assertStringContainsString($owner->email, (string) $zip->getFromName('members.csv'));
        foreach (['api_tokens.csv', 'access_reviews.csv', 'changes_deploys.csv', 'scans.csv', 'patching.csv', 'blocked_attacks.csv', 'backups.csv', 'backup_restore_tests.csv', 'incidents.csv', 'audit_log.csv'] as $file) {
            $this->assertNotFalse($zip->getFromName($file), $file);
        }
        $zip->close();
        $this->assertSame(1, AuditEntry::query()->where('action', 'security_evidence.exported')->count());
    }
}
