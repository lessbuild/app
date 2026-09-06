<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Models\Website;
use App\Presenters\StatusComponentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicStatusPageController extends Controller
{
    public function __construct(private readonly StatusComponentPresenter $presenter) {}

    /** @return Response The published page and subscription form, with shared caching disabled. */
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

    /** @return JsonResponse A public component and incident snapshot for the published slug. */
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

    /**
     * @param  string  $slug  The public status-page identifier.
     * @return StatusPage A published page with counts loaded in the website query, independent of component count.
     */
    private function page(string $slug): StatusPage
    {
        $since = now()->subDays(30);

        return StatusPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->with(['websites' => fn ($query) => $query->withCount([
                'healthChecks as recent_health_checks_count' => fn (Builder $checks) => $checks->where('checked_at', '>=', $since),
                'healthChecks as successful_recent_health_checks_count' => fn (Builder $checks) => $checks
                    ->where('checked_at', '>=', $since)
                    ->where('successful', true),
            ])])
            ->firstOrFail();
    }

    /** @return list<array{name: string, operational: bool, status: string, uptime_30d: ?float, checked_at: ?string}> */
    private function components(StatusPage $page): array
    {
        return $page->websites
            ->map(fn (Website $website): array => $this->presenter->present($website))
            ->values()
            ->all();
    }
}
