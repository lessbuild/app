<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicStatusPageController extends Controller
{
    public function show(string $slug): Response
    {
        $page = $this->page($slug);

        return response()->view('status.show', [
            'page' => $page,
            'components' => $this->components($page),
            'incidents' => $page->incidents()->latest('starts_at')->limit(20)->get(),
        ])
            // The page includes a CSRF-protected subscription form and session
            // feedback, so it must never be stored by a shared cache.
            ->header('Cache-Control', 'no-store, private');
    }

    public function report(string $slug): JsonResponse
    {
        $page = $this->page($slug);
        $components = $this->components($page);

        return response()->json([
            'name' => $page->name,
            'status' => collect($components)->every(fn (array $component): bool => $component['operational']) ? 'operational' : 'degraded',
            'updated_at' => now()->toIso8601String(),
            'components' => $components,
            'incidents' => $page->incidents()->latest('starts_at')->limit(20)->get()->map(fn ($incident) => [
                'kind' => $incident->kind,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'title' => $incident->title,
                'message' => $incident->message,
                'starts_at' => $incident->starts_at->toIso8601String(),
                'ends_at' => $incident->ends_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
            ])->values(),
        ])->header('Cache-Control', 'public, max-age=30');
    }

    private function page(string $slug): StatusPage
    {
        return StatusPage::query()->where('slug', $slug)->where('is_published', true)->with('websites')->firstOrFail();
    }

    /** @return list<array{name: string, operational: bool, status: string, uptime_30d: ?float, checked_at: ?string}> */
    private function components(StatusPage $page): array
    {
        return $page->websites->map(function ($website): array {
            $checks = $website->healthChecks()->where('checked_at', '>=', now()->subDays(30));
            $total = (clone $checks)->count();
            $successful = (clone $checks)->where('successful', true)->count();
            $operational = $website->provisioning_status === Website::STATUS_ACTIVE
                && (! $website->health_check_enabled || $website->health_status !== Website::HEALTH_UNHEALTHY);

            return [
                'name' => $website->pivot->display_name ?: $website->name,
                'operational' => $operational,
                'status' => $operational ? 'Operational' : 'Degraded',
                'uptime_30d' => $total > 0 ? round(($successful / $total) * 100, 3) : null,
                'checked_at' => $website->health_last_checked_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}
