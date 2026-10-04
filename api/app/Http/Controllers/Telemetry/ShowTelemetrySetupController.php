<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\IntegrationSetupRequest;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\IssueTracker;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\CollectionHealthQuery;
use App\Support\Telemetry\IntegrationSetupGuide;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowTelemetrySetupController
{
    /**
     * Show how to connect an app: each environment's ingest keys (never their secrets) and collection health, a working
     * example for the chosen stack (`?stack=`), OpenTelemetry's settings, ticket trackers and browser errors.
     *
     * @param  IntegrationSetupRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  CollectionHealthQuery  $health
     * @param  IntegrationSetupGuide  $guide
     * @return JsonResponse
     */
    public function __invoke(IntegrationSetupRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, CollectionHealthQuery $health, IntegrationSetupGuide $guide): JsonResponse
    {
        $stack = $request->stack();
        $tokens = IngestToken::query()->whereIn('environment_id', $project->environments()->select('id'))->whereNull('revoked_at')->latest('id')->get()->groupBy('environment_id');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'environments' => $health->handle($project)['environments']->map(function (array $item) use ($tokens): array {
                /** @var Environment $environment */
                $environment = $item['environment'];

                return [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'description' => $item['description'],
                    'events' => (int) $environment->telemetry_event_count,
                    'state' => $item['state']->label(),
                    'tone' => $item['state']->tone(),
                    'tokens' => $tokens->get($environment->id, collect())->map(fn (IngestToken $token): array => [
                        'id' => $token->id, 'name' => $token->name, 'prefix' => $token->prefix, 'lastUsedAt' => $token->last_used_at?->toIso8601String(), 'expiresAt' => $token->expires_at?->toIso8601String(),
                    ])->values(),
                    'browserKey' => $environment->browser_key,
                    'browserOrigins' => implode(' ', $environment->browser_origins ?? []),
                ];
            })->values(),
            'browserScript' => asset('monitoring/browser.js'),
            'stack' => $stack,
            'stacks' => $guide->stacks(),
            'guide' => $guide->for($stack, route('api.ingest'), route('api.ingest.receipts.show', 'RECEIPT_ID')),
            'otlp' => $guide->openTelemetryConfiguration(route('api.otlp', 'traces'), route('api.otlp', 'logs'), route('api.otlp', 'metrics')),
            'trackers' => IssueTracker::query()->where('project_id', $project->id)->orderBy('name')->get()->map(fn (IssueTracker $tracker): array => [
                'id' => $tracker->id, 'name' => $tracker->name, 'kind' => IssueTracker::KINDS[$tracker->kind] ?? $tracker->kind, 'destination' => $tracker->destination(),
            ])->values(),
            'trackerKinds' => IssueTracker::KINDS,
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
