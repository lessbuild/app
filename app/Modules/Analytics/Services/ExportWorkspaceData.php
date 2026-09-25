<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\GoalConversion;
use App\Modules\Analytics\Models\GoalVersion;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\SiteIncidentAnnotation;
use App\Modules\Analytics\Models\SiteReleaseAnnotation;
use App\Modules\Analytics\Models\Visit;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Models\WorkspaceUsagePeriod;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class ExportWorkspaceData
{
    public function __construct(private readonly AnalyticsWorkspaceAccess $access) {}

    /**
     * @param  resource  $output
     */
    public function write(Workspace $workspace, mixed $output, Authenticatable $actor): void
    {
        foreach ($this->records($workspace, $actor) as $record) {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            $written = 0;
            $length = strlen($line);

            while ($written < $length) {
                $bytes = fwrite($output, substr($line, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('The Analytics workspace export stream could not be written.');
                }

                $written += $bytes;
            }
        }
    }

    /**
     * @return Generator<int, array{type: string, data: array<string, mixed>}>
     */
    private function records(Workspace $workspace, Authenticatable $actor): Generator
    {
        yield $this->record('export', [
            'format' => 'buildpusher-analytics-workspace-export',
            'version' => 1,
            'site_scope' => 'currently_authorized_sites',
            'exported_at' => now('UTC')->toIso8601String(),
            'record_types' => [
                'workspace',
                'member',
                'invitation',
                'site',
                'usage_period',
                'goal',
                'goal_version',
                'ingestion_batch',
                'event',
                'visit',
                'goal_conversion',
                'report_daily_aggregate',
                'release_annotation',
                'incident_annotation',
                'report_export',
            ],
            'privacy' => [
                'site_verification_tokens_and_report_download_tokens_are_excluded' => true,
                'visitor_and_session_identifiers_are_pseudonymous_and_are_included_to_preserve_analytics_relationships' => true,
                'event_properties_contain_only_the_validated_event_name' => true,
                'expired_report_files_can_be_regenerated_from_the_included_report_data' => true,
            ],
        ]);

        yield $this->record('workspace', [
            'id' => $workspace->getKey(),
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'created_at' => $workspace->created_at?->toIso8601String(),
            'updated_at' => $workspace->updated_at?->toIso8601String(),
        ]);

        foreach ($workspace->users()
            ->select(['users.id', 'users.name', 'users.email'])
            ->orderBy('users.id')
            ->cursor() as $member) {
            yield $this->record('member', [
                'id' => $member->getKey(),
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'joined_at' => $member->pivot->created_at?->toIso8601String(),
                'updated_at' => $member->pivot->updated_at?->toIso8601String(),
            ]);
        }

        foreach (Invitation::query()->where('workspace_id', $workspace->getKey())->orderBy('id')->cursor() as $invitation) {
            yield $this->record('invitation', [
                'id' => $invitation->getKey(),
                'invited_by' => $invitation->invited_by,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'accepted_at' => $invitation->accepted_at?->toIso8601String(),
                'created_at' => $invitation->created_at?->toIso8601String(),
            ]);
        }

        foreach ($this->access->sitesQuery($actor, $workspace, withTrashed: true)->orderBy('id')->cursor() as $site) {
            yield $this->record('site', [
                'id' => $site->getKey(),
                'name' => $site->name,
                'slug' => $site->slug,
                'public_id' => $site->public_id,
                'domains' => $site->domains,
                'excluded_paths' => $site->excluded_paths,
                'timezone' => $site->timezone,
                'verified_at' => $site->verified_at?->toIso8601String(),
                'collection_enabled' => $site->collection_enabled,
                'collection_paused_at' => $site->collection_paused_at?->toIso8601String(),
                'last_event_at' => $site->last_event_at?->toIso8601String(),
                'last_processed_at' => $site->last_processed_at?->toIso8601String(),
                'created_at' => $site->created_at?->toIso8601String(),
                'updated_at' => $site->updated_at?->toIso8601String(),
                'deleted_at' => $site->deleted_at?->toIso8601String(),
            ]);
        }

        foreach (WorkspaceUsagePeriod::query()->where('workspace_id', $workspace->getKey())->orderBy('period_start')->cursor() as $period) {
            yield $this->record('usage_period', [
                'id' => $period->getKey(),
                'period_start' => $period->period_start?->toDateString(),
                'accepted_events' => $period->accepted_events,
            ]);
        }

        foreach (Goal::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $goal) {
            yield $this->record('goal', [
                'id' => $goal->getKey(),
                'site_id' => $goal->site_id,
                'name' => $goal->name,
                'kind' => $goal->kind,
                'match_type' => $goal->match_type,
                'match_value' => $goal->match_value,
                'active' => $goal->active,
                'created_at' => $goal->created_at?->toIso8601String(),
                'updated_at' => $goal->updated_at?->toIso8601String(),
            ]);
        }

        foreach (GoalVersion::query()
            ->whereIn('goal_id', Goal::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->select('id'))
            ->orderBy('id')
            ->cursor() as $version) {
            yield $this->record('goal_version', [
                'id' => $version->getKey(),
                'goal_id' => $version->goal_id,
                'kind' => $version->kind,
                'match_type' => $version->match_type,
                'match_value' => $version->match_value,
                'effective_from' => $version->effective_from?->toIso8601String(),
                'effective_to' => $version->effective_to?->toIso8601String(),
                'created_at' => $version->created_at?->toIso8601String(),
                'updated_at' => $version->updated_at?->toIso8601String(),
            ]);
        }

        foreach (IngestionBatch::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $batch) {
            yield $this->record('ingestion_batch', [
                'id' => $batch->getKey(),
                'site_id' => $batch->site_id,
                'batch_id' => $batch->batch_id,
                'event_count' => $batch->event_count,
                'status' => $batch->status,
                'accepted_at' => $batch->accepted_at?->toIso8601String(),
                'processed_at' => $batch->processed_at?->toIso8601String(),
                'created_at' => $batch->created_at?->toIso8601String(),
                'updated_at' => $batch->updated_at?->toIso8601String(),
            ]);
        }

        foreach (AnalyticsEvent::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $event) {
            yield $this->record('event', [
                'id' => $event->getKey(),
                'site_id' => $event->site_id,
                'ingestion_batch_id' => $event->ingestion_batch_id,
                'event_id' => $event->event_id,
                'type' => $event->type,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'received_at' => $event->received_at?->toIso8601String(),
                'path' => $event->path,
                'referrer_host' => $event->referrer_host,
                'utm_source' => $event->utm_source,
                'utm_medium' => $event->utm_medium,
                'utm_campaign' => $event->utm_campaign,
                'device_category' => $event->device_category,
                'browser' => $event->browser,
                'operating_system' => $event->operating_system,
                'visitor_hash' => $event->visitor_hash,
                'session_id' => $event->session_id,
                'properties' => $event->properties,
                'created_at' => $event->created_at?->toIso8601String(),
                'updated_at' => $event->updated_at?->toIso8601String(),
            ]);
        }

        foreach (Visit::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $visit) {
            yield $this->record('visit', [
                'id' => $visit->getKey(),
                'site_id' => $visit->site_id,
                'visit_key' => $visit->visit_key,
                'visitor_hash' => $visit->visitor_hash,
                'session_id' => $visit->session_id,
                'started_at' => $visit->started_at?->toIso8601String(),
                'last_seen_at' => $visit->last_seen_at?->toIso8601String(),
                'landing_path' => $visit->landing_path,
                'exit_path' => $visit->exit_path,
                'entry_referrer_host' => $visit->entry_referrer_host,
                'entry_utm_source' => $visit->entry_utm_source,
                'entry_utm_medium' => $visit->entry_utm_medium,
                'entry_utm_campaign' => $visit->entry_utm_campaign,
                'pageviews' => $visit->pageviews,
                'conversion_count' => $visit->conversion_count,
                'created_at' => $visit->created_at?->toIso8601String(),
                'updated_at' => $visit->updated_at?->toIso8601String(),
            ]);
        }

        foreach (GoalConversion::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $conversion) {
            yield $this->record('goal_conversion', [
                'id' => $conversion->getKey(),
                'site_id' => $conversion->site_id,
                'goal_id' => $conversion->goal_id,
                'goal_version_id' => $conversion->goal_version_id,
                'analytics_event_id' => $conversion->analytics_event_id,
                'visit_id' => $conversion->visit_id,
                'converted_at' => $conversion->converted_at?->toIso8601String(),
                'created_at' => $conversion->created_at?->toIso8601String(),
                'updated_at' => $conversion->updated_at?->toIso8601String(),
            ]);
        }

        foreach (ReportDailyAggregate::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $aggregate) {
            yield $this->record('report_daily_aggregate', [
                'id' => $aggregate->getKey(),
                'site_id' => $aggregate->site_id,
                'local_date' => $aggregate->local_date?->toDateString(),
                'dimension' => $aggregate->dimension,
                'dimension_value' => $aggregate->dimension_value,
                'pageviews' => $aggregate->pageviews,
                'visits' => $aggregate->visits,
                'visitors' => $aggregate->visitors,
                'conversions' => $aggregate->conversions,
                'converted_visits' => $aggregate->converted_visits,
                'bounce_eligible' => $aggregate->bounce_eligible,
                'bounces' => $aggregate->bounces,
                'created_at' => $aggregate->created_at?->toIso8601String(),
                'updated_at' => $aggregate->updated_at?->toIso8601String(),
            ]);
        }

        foreach (SiteReleaseAnnotation::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $annotation) {
            yield $this->record('release_annotation', [
                'id' => $annotation->getKey(),
                'site_id' => $annotation->site_id,
                'delivery_id' => $annotation->delivery_id,
                'handler' => $annotation->handler,
                'project_connection_id' => $annotation->project_connection_id,
                'deployment_id' => $annotation->deployment_id,
                'source_build_id' => $annotation->source_build_id,
                'version' => $annotation->version,
                'revision' => $annotation->revision,
                'deployed_at' => $annotation->deployed_at?->toIso8601String(),
                'payload_hash' => $annotation->payload_hash,
            ]);
        }

        foreach (SiteIncidentAnnotation::query()->whereIn('site_id', $this->siteIds($workspace, $actor))->orderBy('id')->cursor() as $annotation) {
            yield $this->record('incident_annotation', [
                'id' => $annotation->getKey(),
                'site_id' => $annotation->site_id,
                'delivery_id' => $annotation->delivery_id,
                'handler' => $annotation->handler,
                'project_connection_id' => $annotation->project_connection_id,
                'source_incident_id' => $annotation->source_incident_id,
                'status' => $annotation->status,
                'occurred_at' => $annotation->occurred_at?->toIso8601String(),
                'payload_hash' => $annotation->payload_hash,
            ]);
        }

        foreach (ReportExport::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('site_id', $this->siteIds($workspace, $actor))
            ->orderBy('id')->cursor() as $report) {
            yield $this->record('report_export', [
                'id' => $report->getKey(),
                'site_id' => $report->site_id,
                'requested_by' => $report->requested_by,
                'status' => $report->status,
                'filters' => $report->filters,
                'expires_at' => $report->expires_at?->toIso8601String(),
                'completed_at' => $report->completed_at?->toIso8601String(),
                'created_at' => $report->created_at?->toIso8601String(),
                'updated_at' => $report->updated_at?->toIso8601String(),
            ]);
        }
    }

    /** @return Builder<Site> */
    private function siteIds(Workspace $workspace, Authenticatable $actor): Builder
    {
        return $this->access->sitesQuery($actor, $workspace, withTrashed: true)->select('id');
    }

    /** @param array<string, mixed> $data
     * @return array{type: string, data: array<string, mixed>}
     */
    private function record(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }
}
