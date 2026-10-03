<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\BackupVerification;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\SecurityBlock;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Queries\Security\ProjectServersQuery;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Builds a compliance evidence pack: a ZIP of CSV exports of what the platform records (access, reviews, changes,
 * vulnerability management, patching, backups, incidents and the audit log) for a period, with a README mapping each
 * file to the SOC 2 and ISO 27001 controls it supports.
 */
final class EvidencePack
{
    /**
     * Create a new EvidencePack instance.
     *
     * @param  ProjectServersQuery  $servers  Finds the project's servers.
     */
    public function __construct(private readonly ProjectServersQuery $servers) {}

    /**
     * Write the pack to a temporary file and return its path. The caller deletes it after sending.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return string
     */
    public function build(Project $project, CarbonImmutable $from, CarbonImmutable $until): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Couldn’t create the evidence pack.');
        }
        $between = [$from, $until];
        $websites = Website::query()->whereIn('environment_id', $project->environments()->select('id'))->pluck('name', 'id');
        $files = [
            'members.csv' => [['Name', 'Email', 'Role', 'Two-factor', 'Projects', 'Member since'], Membership::query()->where('account_id', $project->account_id)->with('user')->get()
                ->map(fn (Membership $m): array => [$m->user->name, $m->user->email, $m->role->label(), $m->user->two_factor_confirmed_at === null ? 'off' : 'on', $m->project_ids === null ? 'all' : implode(' ', $m->project_ids), (string) $m->created_at?->toDateString()])->all()],
            'api_tokens.csv' => [['Name', 'Owner', 'Abilities', 'Created', 'Last used', 'Expires'], ApiToken::query()->where('account_id', $project->account_id)->get()
                ->map(fn (ApiToken $t): array => [$t->name, (string) User::query()->whereKey($t->tokenable_id)->value('email'), implode(' ', $t->abilities), (string) $t->created_at?->toDateString(), (string) $t->last_used_at?->toDateString(), (string) $t->expires_at?->toDateString()])->all()],
            'access_reviews.csv' => [['Date', 'Reviewer', 'Members', 'API tokens', 'SSH grants', 'Removed'], SecurityAccessReview::query()->where('project_id', $project->id)->whereBetween('created_at', $between)->with('reviewer')->get()
                ->map(fn (SecurityAccessReview $r): array => [(string) $r->created_at?->toDateTimeString(), $r->reviewer->email ?? '', $r->summary['members'], $r->summary['tokens'], $r->summary['grants'], implode('; ', $r->summary['removed'])])->all()],
            'changes_deploys.csv' => [['Deploy', 'Website', 'Environment', 'Status', 'Trigger', 'Requested by', 'Approved by', 'Revision', 'Started', 'Finished'], Build::query()->whereIn('environment_id', $project->environments()->select('id'))->whereBetween('created_at', $between)->with(['requester', 'approver', 'environment'])->orderBy('id')->get()
                ->map(fn (Build $b): array => [$b->id, (string) ($websites[$b->website_id] ?? ''), (string) $b->environment?->name, $b->status, $b->trigger_source, (string) $b->requester?->email, (string) $b->approver?->email, (string) $b->revision, (string) $b->started_at?->toDateTimeString(), (string) $b->finished_at?->toDateTimeString()])->all()],
            'vulnerabilities.csv' => [['Check', 'Severity', 'Finding', 'Subject', 'Status', 'First seen', 'Resolved', 'Ignored because'], SecurityFinding::query()->where('project_id', $project->id)->where('last_seen_at', '>=', $from)->orderBy('first_seen_at')->get()
                ->map(fn (SecurityFinding $f): array => [$f->source, $f->severity, $f->title, (string) $f->subject, $f->status, $f->first_seen_at->toDateTimeString(), (string) $f->resolved_at?->toDateTimeString(), (string) $f->ignored_reason])->all()],
            'scans.csv' => [['Check', 'Status', 'Open findings', 'Finished', 'Error'], SecurityScan::query()->where('project_id', $project->id)->whereBetween('created_at', $between)->orderBy('id')->get()
                ->map(fn (SecurityScan $s): array => [$s->kind, $s->status, $s->findings_count, (string) $s->finished_at?->toDateTimeString(), (string) $s->error])->all()],
            'patching.csv' => [['Server', 'Update window (UTC)', 'Reboots when needed', 'Last updated', 'Last error'], $this->servers->handle($project)
                ->map(fn ($server): array => [$server->name, $server->patch_day === null ? 'none' : 'day '.$server->patch_day.' at '.$server->patch_hour.':00', $server->patch_reboot ? 'yes' : 'no', (string) $server->last_patched_at?->toDateTimeString(), (string) $server->last_patch_error])->all()],
            'blocked_attacks.csv' => [['Address', 'Reason', 'Hits', 'Blocked', 'Until', 'Lifted'], SecurityBlock::query()->where('project_id', $project->id)->whereBetween('created_at', $between)->orderBy('id')->get()
                ->map(fn (SecurityBlock $b): array => [$b->ip, $b->reason, $b->hits, (string) $b->created_at?->toDateTimeString(), $b->expires_at->toDateTimeString(), (string) $b->lifted_at?->toDateTimeString()])->all()],
            'backups.csv' => [['Website', 'Status', 'Size (bytes)', 'Completed', 'Sent over HTTPS', 'Error'], WebsiteBackup::query()->whereIn('website_id', $websites->keys())->whereBetween('created_at', $between)->orderBy('id')->get()
                ->map(fn (WebsiteBackup $b): array => [(string) ($websites[$b->website_id] ?? ''), $b->status, (string) $b->size_bytes, (string) $b->completed_at?->toDateTimeString(), $b->https_verified_at === null ? 'no' : 'yes', (string) $b->error])->all()],
            'backup_restore_tests.csv' => [['Backup', 'Status', 'Integrity', 'Smoke test', 'Seconds', 'Tested'], BackupVerification::query()->whereIn('website_backup_id', WebsiteBackup::query()->whereIn('website_id', $websites->keys())->select('id'))->whereBetween('created_at', $between)->orderBy('id')->get()
                ->map(fn (BackupVerification $v): array => [$v->website_backup_id, $v->status, $v->integrity_status, $v->smoke_status, (string) $v->duration_seconds, (string) $v->created_at?->toDateTimeString()])->all()],
            'incidents.csv' => [['Incident', 'Status', 'Opened', 'Acknowledged', 'Resolved', 'Post-mortem'], Incident::query()->where('project_id', $project->id)->whereBetween('opened_at', $between)->orderBy('opened_at')->get()
                ->map(fn (Incident $i): array => [$i->title, $i->status, $i->opened_at->toDateTimeString(), (string) $i->acknowledged_at?->toDateTimeString(), (string) $i->resolved_at?->toDateTimeString(), $i->postmortem === null ? 'no' : 'yes'])->all()],
            'audit_log.csv' => [['When', 'Who', 'What', 'IP address'], AuditEntry::query()->where('account_id', $project->account_id)->whereBetween('created_at', $between)->orderBy('created_at')->get()
                ->map(fn (AuditEntry $e): array => [$e->created_at->toDateTimeString(), (string) ($e->actor_email ?? $e->actor_name), $e->action->describe($e->context ?? []), (string) $e->ip_address])->all()],
        ];
        foreach ($files as $name => [$header, $rows]) {
            $zip->addFromString($name, $this->csv([$header, ...$rows]));
        }
        $zip->addFromString('README.md', $this->readme($project, $from, $until, array_map(fn (array $file): int => count($file[1]), $files)));
        $zip->close();

        return $path;
    }

    /**
     * Turn rows into CSV text, neutralising cells that spreadsheets would run as formulas.
     *
     * @param  list<list<mixed>>  $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Couldn’t write the evidence pack.');
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (mixed $cell): string => preg_match('/^[=+\-@\t\r]/', (string) $cell) === 1 ? "'".$cell : (string) $cell, $row), escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Write the README: the period, and which controls each file gives evidence for.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, int>  $counts  rows per file
     * @return string
     */
    private function readme(Project $project, CarbonImmutable $from, CarbonImmutable $until, array $counts): string
    {
        $controls = [
            'members.csv' => 'Who has access, their role and whether they use two-factor sign-in. SOC 2 CC6.1, CC6.2; ISO 27001 A.5.15, A.5.18, A.8.5.',
            'api_tokens.csv' => 'Machine access: tokens, their abilities and use. SOC 2 CC6.1; ISO 27001 A.5.17.',
            'access_reviews.csv' => 'Periodic access reviews and what they removed. SOC 2 CC6.2, CC6.3; ISO 27001 A.5.18.',
            'changes_deploys.csv' => 'Every change deployed, who asked for it and who approved it. SOC 2 CC8.1; ISO 27001 A.8.32.',
            'vulnerabilities.csv' => 'Vulnerabilities found (packages, secrets, servers, domains) and how each was handled. SOC 2 CC7.1; ISO 27001 A.8.8.',
            'scans.csv' => 'That vulnerability checks ran, and when. SOC 2 CC7.1; ISO 27001 A.8.8.',
            'patching.csv' => 'Security update windows per server. SOC 2 CC7.1; ISO 27001 A.8.8, A.8.19.',
            'blocked_attacks.csv' => 'Attacks detected and blocked. SOC 2 CC6.6, CC7.2; ISO 27001 A.8.16, A.8.20.',
            'backups.csv' => 'Backups taken and sent encrypted. SOC 2 A1.2; ISO 27001 A.8.13.',
            'backup_restore_tests.csv' => 'Backups restored and tested. SOC 2 A1.3; ISO 27001 A.8.13.',
            'incidents.csv' => 'Incidents, response times and post-mortems. SOC 2 CC7.3, CC7.4, CC7.5; ISO 27001 A.5.24–A.5.27.',
            'audit_log.csv' => 'Every important action in the account, with who and from where. SOC 2 CC7.2; ISO 27001 A.8.15.',
        ];
        $lines = ["# Security evidence: {$project->name}", '', 'Period: '.$from->toDateString().' to '.$until->toDateString().' (UTC). Generated '.now()->toDateTimeString().' by '.config('app.name').'.', '', '| File | Rows | What it shows |', '| --- | ---: | --- |'];
        foreach ($controls as $file => $text) {
            $lines[] = "| {$file} | ".($counts[$file] ?? 0)." | {$text} |";
        }
        $lines[] = '';
        $lines[] = 'Control references are a guide for your auditor; they don’t on their own make you compliant.';

        return implode("\n", $lines)."\n";
    }
}
