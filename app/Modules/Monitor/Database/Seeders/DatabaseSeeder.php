<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\CreateWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(CreateWorkspace $createWorkspace, CreateIngestToken $createToken): void
    {
        $owner = User::factory()->create([
            'name' => 'Sam Chen',
            'email' => 'sam@beacon.test',
            'password' => Str::random(64),
        ]);
        $workspace = $createWorkspace->create($owner, 'Acme Systems');

        $polaris = $workspace->applications()->create([
            'name' => 'Polaris Commerce',
            'slug' => 'polaris-commerce',
            'framework' => 'Laravel',
            'framework_version' => '13.x',
            'accent' => 'violet',
        ]);
        $atlas = $workspace->applications()->create([
            'name' => 'Atlas API',
            'slug' => 'atlas-api',
            'framework' => 'Node.js',
            'framework_version' => '22.x',
            'accent' => 'sky',
        ]);
        $ledger = $workspace->applications()->create([
            'name' => 'Ledger Worker',
            'slug' => 'ledger-worker',
            'framework' => 'Python',
            'framework_version' => '3.13',
            'accent' => 'amber',
        ]);

        $polarisProduction = $polaris->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
        ]);
        $polarisStaging = $polaris->environments()->create([
            'name' => 'Staging',
            'slug' => 'staging',
            'status' => 'active',
        ]);
        $atlasProduction = $atlas->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
        ]);
        $ledgerProduction = $ledger->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
        ]);

        $now = CarbonImmutable::now();
        $eventCounts = [];

        $recordEvent = function (Environment $environment, string $seed, array $event, int $minutesAgo) use (&$eventCounts, $now): void {
            $occurredAt = $now->subMinutes($minutesAgo);
            $issueFields = ['title', 'fingerprint', 'affected_users', 'details'];
            $payload = $event['payload'] ?? [];

            foreach ($issueFields as $issueField) {
                if (array_key_exists($issueField, $event)) {
                    $payload[$issueField] = $event[$issueField];
                }
            }

            $event = array_diff_key($event, array_flip($issueFields));

            if ($payload !== []) {
                $event['payload'] = $payload;
            }

            TelemetryEvent::query()->create(array_merge([
                'environment_id' => $environment->id,
                'dedupe_key' => hash('sha256', $environment->id.'|seed|'.$seed),
                'severity' => 'info',
                'occurred_at' => $occurredAt,
            ], $event));

            $eventCounts[$environment->id] = ($eventCounts[$environment->id] ?? 0) + 1;
        };

        $recordEvent($polarisProduction, 'checkout-request', [
            'trace_id' => 'trace_checkout_001',
            'span_id' => 'span_req_001',
            'type' => 'request',
            'name' => 'GET /checkout',
            'route' => '/checkout',
            'service' => 'polaris-web',
            'status_code' => 200,
            'duration_ms' => 182.4,
            'attributes' => ['http.method' => 'GET', 'user.id' => 'usr_2048'],
        ], 2);
        $recordEvent($polarisProduction, 'checkout-query', [
            'trace_id' => 'trace_checkout_001',
            'span_id' => 'span_sql_001',
            'parent_span_id' => 'span_req_001',
            'type' => 'query',
            'name' => 'select * from orders where user_id = ?',
            'route' => '/checkout',
            'service' => 'mysql',
            'duration_ms' => 41.8,
            'attributes' => ['db.system' => 'mysql', 'db.rows_affected' => 8],
        ], 2);
        $recordEvent($polarisProduction, 'checkout-cache', [
            'trace_id' => 'trace_checkout_001',
            'span_id' => 'span_cache_001',
            'parent_span_id' => 'span_req_001',
            'type' => 'log',
            'name' => 'Cache hit: checkout.tax_rates',
            'route' => '/checkout',
            'service' => 'redis',
            'duration_ms' => 1.2,
        ], 2);
        $recordEvent($polarisProduction, 'reports-request', [
            'trace_id' => 'trace_reports_003',
            'span_id' => 'span_req_003',
            'type' => 'request',
            'name' => 'GET /reports/receivables',
            'route' => '/reports/receivables',
            'service' => 'polaris-web',
            'status_code' => 200,
            'duration_ms' => 2431.7,
        ], 14);
        $recordEvent($polarisProduction, 'reports-query', [
            'trace_id' => 'trace_reports_003',
            'span_id' => 'span_sql_003',
            'parent_span_id' => 'span_req_003',
            'type' => 'query',
            'name' => 'select * from invoices order by due_at',
            'route' => '/reports/receivables',
            'service' => 'mysql',
            'duration_ms' => 2190.4,
            'severity' => 'warning',
        ], 14);
        $recordEvent($polarisProduction, 'duplicate-flight-exception', [
            'trace_id' => 'trace_invoice_014',
            'span_id' => 'span_exc_014',
            'type' => 'exception',
            'severity' => 'critical',
            'name' => 'Illuminate\\Database\\QueryException',
            'title' => 'SQL integrity constraint violation on invoice insert',
            'route' => 'POST /invoices',
            'service' => 'polaris-web',
            'status_code' => 500,
            'duration_ms' => 518.6,
            'fingerprint' => hash('sha256', 'duplicate-invoice-insert'),
            'affected_users' => 14,
            'details' => 'Duplicate invoice numbers are being generated during concurrent checkout retries.',
            'attributes' => ['exception.handled' => false],
        ], 27);
        $recordEvent($polarisProduction, 'sync-job', [
            'trace_id' => 'trace_sync_091',
            'span_id' => 'span_job_091',
            'type' => 'job',
            'name' => 'SyncCustomerLedger',
            'route' => 'queue:billing',
            'service' => 'polaris-worker',
            'duration_ms' => 864.1,
            'attributes' => ['job.attempt' => 2, 'queue.name' => 'billing'],
        ], 31);
        $recordEvent($polarisStaging, 'staging-request', [
            'trace_id' => 'trace_staging_007',
            'span_id' => 'span_req_007',
            'type' => 'request',
            'name' => 'GET /dashboard',
            'route' => '/dashboard',
            'service' => 'polaris-web',
            'status_code' => 200,
            'duration_ms' => 92.3,
        ], 48);
        $recordEvent($atlasProduction, 'payment-request', [
            'trace_id' => 'trace_payment_118',
            'span_id' => 'span_req_118',
            'type' => 'request',
            'name' => 'POST /v1/payments',
            'route' => '/v1/payments',
            'service' => 'atlas-api',
            'status_code' => 201,
            'duration_ms' => 326.2,
        ], 4);
        $recordEvent($atlasProduction, 'provider-exception', [
            'trace_id' => 'trace_payment_122',
            'span_id' => 'span_exc_122',
            'type' => 'exception',
            'severity' => 'error',
            'name' => 'UpstreamProviderError',
            'title' => 'Billing provider returned a 502 response',
            'route' => 'POST /v1/payments',
            'service' => 'atlas-api',
            'status_code' => 502,
            'duration_ms' => 1842.7,
            'fingerprint' => hash('sha256', 'billing-provider-502'),
            'affected_users' => 31,
            'details' => 'The billing provider returned a transient gateway error for 31 payment attempts.',
        ], 11);
        $recordEvent($atlasProduction, 'webhook-log', [
            'trace_id' => 'trace_webhook_220',
            'span_id' => 'span_log_220',
            'type' => 'log',
            'severity' => 'warning',
            'name' => 'Webhook signature mismatch',
            'route' => 'POST /webhooks/stripe',
            'service' => 'atlas-api',
            'status_code' => 401,
            'duration_ms' => 12.3,
        ], 36);
        $recordEvent($ledgerProduction, 'ledger-job', [
            'trace_id' => 'trace_ledger_501',
            'span_id' => 'span_job_501',
            'type' => 'job',
            'name' => 'reconcile_daily_ledger',
            'route' => 'queue:reconciliation',
            'service' => 'ledger-worker',
            'duration_ms' => 4122.9,
            'attributes' => ['job.attempt' => 1, 'queue.name' => 'reconciliation'],
        ], 19);
        $recordEvent($ledgerProduction, 'ledger-exception', [
            'trace_id' => 'trace_ledger_504',
            'span_id' => 'span_exc_504',
            'type' => 'exception',
            'severity' => 'error',
            'name' => 'RedisTimeoutError',
            'title' => 'Redis connection timed out while locking ledger batch',
            'route' => 'reconcile_daily_ledger',
            'service' => 'ledger-worker',
            'duration_ms' => 9998.4,
            'fingerprint' => hash('sha256', 'ledger-redis-timeout'),
            'affected_users' => 7,
            'details' => 'The reconciliation worker could not acquire the distributed lock before its deadline.',
        ], 43);

        $environmentLastSeen = [
            $polarisProduction,
            $polarisStaging,
            $atlasProduction,
            $ledgerProduction,
        ];

        foreach ($environmentLastSeen as $environment) {
            $createToken->create($environment, $owner, 'Demo collector')
                ->token->forceFill(['revoked_at' => now()])->save();
            $environment->update([
                'event_count' => $eventCounts[$environment->id] ?? 0,
                'last_seen_at' => $now->subMinutes(2),
            ]);
        }

        Issue::query()->create([
            'application_id' => $polaris->id,
            'environment_id' => $polarisProduction->id,
            'fingerprint' => hash('sha256', 'duplicate-invoice-insert'),
            'type' => 'exception',
            'severity' => 'critical',
            'status' => 'open',
            'title' => 'SQL integrity constraint violation on invoice insert',
            'location' => 'POST /invoices',
            'occurrences' => 128,
            'affected_users' => 14,
            'first_seen_at' => $now->subDays(4),
            'last_seen_at' => $now->subMinutes(27),
            'details' => 'Duplicate invoice numbers are being generated during concurrent checkout retries.',
            'metadata' => ['owner' => 'Payments', 'release' => '2026.09.20.2'],
        ]);
        Issue::query()->create([
            'application_id' => $polaris->id,
            'environment_id' => $polarisProduction->id,
            'fingerprint' => hash('sha256', 'receivables-slow-query'),
            'type' => 'performance',
            'severity' => 'warning',
            'status' => 'open',
            'title' => 'Receivables report exceeded its performance threshold',
            'location' => 'GET /reports/receivables',
            'occurrences' => 63,
            'affected_users' => 21,
            'first_seen_at' => $now->subDays(2),
            'last_seen_at' => $now->subMinutes(14),
            'details' => 'The report spends most of its request time sorting invoice rows in the database.',
            'metadata' => ['threshold_ms' => 2000, 'observed_ms' => 2431],
        ]);
        Issue::query()->create([
            'application_id' => $atlas->id,
            'environment_id' => $atlasProduction->id,
            'fingerprint' => hash('sha256', 'billing-provider-502'),
            'type' => 'exception',
            'severity' => 'error',
            'status' => 'open',
            'title' => 'Billing provider returned a 502 response',
            'location' => 'POST /v1/payments',
            'occurrences' => 42,
            'affected_users' => 31,
            'first_seen_at' => $now->subDays(1),
            'last_seen_at' => $now->subMinutes(11),
            'details' => 'The billing provider returned a transient gateway error for payment attempts.',
            'metadata' => ['owner' => 'Checkout', 'provider' => 'Stripe'],
        ]);
        Issue::query()->create([
            'application_id' => $atlas->id,
            'environment_id' => $atlasProduction->id,
            'fingerprint' => hash('sha256', 'webhook-signature-mismatch'),
            'type' => 'exception',
            'severity' => 'warning',
            'status' => 'resolved',
            'title' => 'Webhook signature mismatch',
            'location' => 'POST /webhooks/stripe',
            'occurrences' => 17,
            'affected_users' => 0,
            'first_seen_at' => $now->subDays(5),
            'last_seen_at' => $now->subDays(1),
            'details' => 'A stale webhook secret was deployed for one application instance.',
            'metadata' => ['owner' => 'Platform', 'resolved_by' => 'Sam Chen'],
        ]);
        Issue::query()->create([
            'application_id' => $ledger->id,
            'environment_id' => $ledgerProduction->id,
            'fingerprint' => hash('sha256', 'ledger-redis-timeout'),
            'type' => 'exception',
            'severity' => 'error',
            'status' => 'open',
            'title' => 'Redis connection timed out while locking ledger batch',
            'location' => 'reconcile_daily_ledger',
            'occurrences' => 19,
            'affected_users' => 7,
            'first_seen_at' => $now->subDays(3),
            'last_seen_at' => $now->subMinutes(43),
            'details' => 'The reconciliation worker could not acquire the distributed lock before its deadline.',
            'metadata' => ['owner' => 'Finance data', 'queue' => 'reconciliation'],
        ]);
    }
}
