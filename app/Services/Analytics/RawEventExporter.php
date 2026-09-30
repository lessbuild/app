<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Services\Storage\S3Client;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Exports sites' raw events to their storage bucket, one gzipped NDJSON file per day under
 * `<prefix>/site=<id>/dt=<date>/events.ndjson.gz` (the layout BigQuery, Athena and Spark read as partitions).
 */
final class RawEventExporter
{
    /**
     * The most days one run exports per site, so a long backlog is caught up over a few nights.
     *
     * @var int
     */
    private const MAX_DAYS_PER_RUN = 7;

    /**
     * Create a new RawEventExporter instance.
     *
     * @param  S3Client  $s3  Uploads the files.
     */
    public function __construct(private readonly S3Client $s3) {}

    /**
     * Export every finished day not yet exported (starting yesterday for a new export) for each site with a bucket,
     * up to a week per site per run. Returns how many files were written.
     *
     * @return int
     */
    public function exportDue(): int
    {
        $written = 0;
        AnalyticsSite::query()->whereNotNull('export_bucket_id')->with('exportBucket')->orderBy('id')->each(function (AnalyticsSite $site) use (&$written): void {
            $yesterday = CarbonImmutable::now($site->timezone)->subDay()->startOfDay();
            $day = $site->exported_until === null ? $yesterday : CarbonImmutable::parse($site->exported_until->toDateString(), $site->timezone)->addDay();
            for ($count = 0; $day->lte($yesterday) && $count < self::MAX_DAYS_PER_RUN; $count++, $day = $day->addDay()) {
                try {
                    $this->exportDay($site, $day);
                } catch (Throwable $exception) {
                    $site->forceFill(['export_error' => str($exception->getMessage())->limit(500)->toString()])->save();

                    return;
                }
                $site->forceFill(['exported_until' => $day->toDateString(), 'export_error' => null])->save();
                $written++;
            }
        });

        return $written;
    }

    /**
     * Write one local day's countable events to the site's bucket, oldest first, one JSON object per line.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $day  the day, in the site's timezone
     * @return void
     */
    public function exportDay(AnalyticsSite $site, CarbonImmutable $day): void
    {
        $bucket = $site->exportBucket;
        if ($bucket === null) {
            return;
        }
        $lines = '';
        AnalyticsEvent::query()->where('site_id', $site->id)->countable()
            ->whereBetween('occurred_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
            ->orderBy('occurred_at')->orderBy('id')
            ->each(function (AnalyticsEvent $event) use (&$lines): void {
                $lines .= json_encode([
                    'event_id' => $event->event_id, 'type' => $event->type, 'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
                    'path' => $event->path, 'referrer_host' => $event->referrer_host, 'channel' => $event->channel,
                    'utm_source' => $event->utm_source, 'utm_medium' => $event->utm_medium, 'utm_campaign' => $event->utm_campaign,
                    'utm_term' => $event->utm_term, 'utm_content' => $event->utm_content,
                    'device' => $event->device_category, 'screen_size' => $event->screen_size,
                    'browser' => $event->browser, 'browser_version' => $event->browser_version,
                    'os' => $event->operating_system, 'os_version' => $event->os_version,
                    'country' => $event->country_code, 'region' => $event->region, 'city' => $event->city,
                    'visitor_hash' => $event->visitor_hash, 'session_id' => $event->session_id, 'returning_hash' => $event->returning_hash,
                    'properties' => $event->properties,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            }, 2000);
        $prefix = trim((string) $site->export_prefix, '/');
        $key = ($prefix === '' ? '' : $prefix.'/').'site='.$site->public_id.'/dt='.$day->toDateString().'/events.ndjson.gz';
        $response = $this->s3->request($bucket->location(), 'PUT', $key, (string) gzencode($lines, 6), 120);
        $this->s3->assertSuccessful('Uploading the export', $response);
    }
}
