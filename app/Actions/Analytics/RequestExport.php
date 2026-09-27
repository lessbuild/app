<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Jobs\Analytics\GenerateReportExport;
use App\Models\AnalyticsExport;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RequestExport
{
    /**
     * Queue a CSV of the report with these filters. Returns the secret token for its status/download page.
     *
     * @param  array<string, string|int|null>  $filters
     */
    public function handle(User $actor, AnalyticsSite $site, array $filters): string
    {
        Gate::forUser($actor)->authorize('export', $site);

        $token = Str::random(64);
        $export = new AnalyticsExport;
        $export->forceFill([
            'site_id' => $site->id,
            'requested_by' => $actor->id,
            'token_hash' => hash('sha256', $token),
            'filters' => $filters,
            'expires_at' => now()->addHours((int) config('analytics.export_retention_hours')),
        ])->save();
        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $token;
    }
}
