<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsImport;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class ImportGoogleAnalytics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One try: Google errors are reported rather than retried.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Up to half an hour for large properties.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * Our dimensions and the GA4 dimension each is read from; `all` is the whole site.
     *
     * @var array<string, string|null>
     */
    public const DIMENSIONS = [
        'all' => null, 'path' => 'pagePath', 'source' => 'sessionSource', 'channel' => 'sessionDefaultChannelGroup',
        'country' => 'countryId', 'city' => 'city', 'device' => 'deviceCategory', 'browser' => 'browser',
        'operating_system' => 'operatingSystem', 'campaign' => 'sessionCampaignName',
    ];

    /**
     * Create a new ImportGoogleAnalytics instance.
     *
     * Imports a Google Analytics property's history into a site's daily totals.
     *
     * @param  int  $importId  The import.
     */
    public function __construct(public readonly int $importId) {}

    /**
     * Read the property's daily totals for the chosen dates, per dimension, and store them as the site's daily
     * totals, stopping the day before the site's own data begins so nothing is counted twice. Then forget the Google
     * connection and record the last imported day on the site, so reports that reach back that far use the totals.
     *
     * @param  GoogleAnalyticsData  $google
     * @return void
     */
    public function handle(GoogleAnalyticsData $google): void
    {
        $import = AnalyticsImport::query()->with('site')->find($this->importId);
        if ($import === null || $import->status !== 'queued' || $import->refresh_token === null || $import->property === null || $import->from_date === null || $import->until_date === null) {
            return;
        }
        $site = $import->site;
        $from = CarbonImmutable::instance($import->from_date);
        $until = CarbonImmutable::instance($import->until_date);
        $import->forceFill(['status' => 'running'])->save();
        $firstOwn = $site->events()->min('occurred_at');
        if ($firstOwn !== null) {
            $until = $until->min(CarbonImmutable::parse((string) $firstOwn, 'UTC')->setTimezone($site->timezone)->startOfDay()->subDay());
        }
        if ($until->lt($from)) {
            $this->finish($import, 0, null, __('Nothing to import: the site has its own data from the start of those dates.'));

            return;
        }

        $now = now();
        $days = [];
        foreach (self::DIMENSIONS as $dimension => $source) {
            $rows = [];
            foreach ($google->daily((string) $import->refresh_token, (string) $import->property, $from, $until, $source) as $row) {
                $value = $source === null ? null : $this->value($dimension, (string) $row['value']);
                $key = $row['date'].'|'.$value;
                if ($dimension === 'campaign' && $value === null) {
                    continue;
                }
                $days[$row['date']] = true;
                $rows[$key] ??= ['site_id' => $site->id, 'local_date' => $row['date'], 'dimension' => $dimension, 'dimension_value' => $source === null ? null : ($value ?? 'Unknown'),
                    'pageviews' => 0, 'visits' => 0, 'visitors' => 0, 'conversions' => 0, 'converted_visits' => 0, 'bounce_eligible' => 0, 'bounces' => 0, 'duration_seconds' => 0,
                    'created_at' => $now, 'updated_at' => $now];
                $rows[$key]['pageviews'] += $row['pageviews'];
                $rows[$key]['visits'] += $row['visits'];
                $rows[$key]['visitors'] += $row['visitors'];
                $rows[$key]['bounce_eligible'] += $row['visits'];
                $rows[$key]['bounces'] += $row['bounces'];
                $rows[$key]['duration_seconds'] += $row['duration'];
            }
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                AnalyticsDailyAggregate::query()->upsert($chunk, ['site_id', 'local_date', 'dimension', 'dimension_value'], [
                    'pageviews', 'visits', 'visitors', 'bounce_eligible', 'bounces', 'duration_seconds', 'updated_at',
                ]);
            }
        }

        $site->forceFill(['imported_until' => $site->imported_until === null ? $until->toDateString() : CarbonImmutable::instance($site->imported_until)->max($until)->toDateString()])->save();
        $this->finish($import, count($days), $until, null);
    }

    /**
     * Record that the import failed, and forget the Google connection.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import !== null) {
            $this->finish($import, $import->days_imported, null, str($exception->getMessage())->limit(500)->toString(), 'failed');
        }
    }

    /**
     * Turn a GA value into ours: unknowns become null, devices and systems use our names.
     *
     * @param  string  $dimension
     * @param  string  $value
     * @return string|null
     */
    private function value(string $dimension, string $value): ?string
    {
        if ($value === '' || in_array($value, ['(not set)', '(other)', '(none)'], true) || ($dimension === 'campaign' && str_starts_with($value, '('))) {
            return null;
        }

        return match ($dimension) {
            'device' => ucfirst(strtolower($value)),
            'operating_system' => ['Macintosh' => 'macOS', 'Chrome OS' => 'ChromeOS'][$value] ?? $value,
            'source' => $value === '(direct)' ? 'Direct / unknown' : $value,
            'path' => mb_substr($value, 0, 2048),
            default => mb_substr($value, 0, 255),
        };
    }

    /**
     * Mark the import finished and forget its Google connection.
     *
     * @param  AnalyticsImport  $import
     * @param  int  $days
     * @param  CarbonImmutable|null  $until  the last day imported
     * @param  string|null  $error
     * @param  string  $status
     * @return void
     */
    private function finish(AnalyticsImport $import, int $days, ?CarbonImmutable $until, ?string $error, string $status = 'done'): void
    {
        $import->forceFill([
            'status' => $status,
            'days_imported' => $days, 'until_date' => $until?->toDateString() ?? $import->until_date, 'error' => $error,
            'refresh_token' => null, 'finished_at' => now(),
        ])->save();
    }
}
