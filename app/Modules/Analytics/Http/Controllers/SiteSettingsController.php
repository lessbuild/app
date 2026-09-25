<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use App\Modules\Analytics\Services\Deletion\AnalyticsSiteDeletionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    public function edit(Site $site): View
    {
        $this->authorize('manage', $site);

        return view('analytics::sites.settings', compact('site'));
    }

    public function update(Request $request, Site $site, AnalyticsDeletionFence $fence): RedirectResponse
    {
        $this->authorize('manage', $site);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domains' => ['required', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone'],
            'excluded_paths' => ['nullable', 'string', 'max:4000'],
            'collection_enabled' => ['sometimes', 'boolean'],
            'collection_paused' => ['sometimes', 'boolean'],
        ]);

        $domains = collect(preg_split('/[,\r\n]+/', $validated['domains']) ?: [])
            ->map(fn (string $domain): string => $this->normalizeDomain($domain))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($domains === [] || collect($domains)->contains(fn (string $domain): bool => ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain))) {
            return back()->withErrors(['domains' => 'Add at least one valid hostname.'])->withInput();
        }

        $excludedPaths = collect(preg_split('/\R/', (string) ($validated['excluded_paths'] ?? '')) ?: [])
            ->map(fn (string $path): string => trim($path))
            ->filter(fn (string $path): bool => $path !== '')
            ->map(fn (string $path): string => Str::startsWith($path, '/') || Str::startsWith($path, '*') ? $path : '/'.$path)
            ->take(50)
            ->values()
            ->all();

        try {
            $domainChanged = DB::connection('analytics')->transaction(function () use ($site, $fence, $validated, $domains, $excludedPaths): bool {
                $workspace = $site->workspace()->lockForUpdate()->firstOrFail();
                $lockedSite = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
                $fence->assertWorkspaceOpen($workspace->getKey());
                $fence->assertSiteOpen($lockedSite->getKey());
                $this->authorize('manage', $lockedSite);
                if ($lockedSite->events()->exists() && $validated['timezone'] !== $lockedSite->timezone) {
                    throw ValidationException::withMessages([
                        'timezone' => 'The reporting timezone cannot change after collection begins.',
                    ]);
                }
                $domainChanged = $domains !== ($lockedSite->domains ?? []);
                $lockedSite->update([
                    'name' => $validated['name'], 'domains' => $domains, 'timezone' => $validated['timezone'],
                    'excluded_paths' => $excludedPaths,
                    'collection_enabled' => (bool) ($validated['collection_enabled'] ?? false),
                    'collection_paused_at' => ($validated['collection_paused'] ?? false) ? now() : null,
                    'verified_at' => $domainChanged ? null : $lockedSite->verified_at,
                ]);

                return $domainChanged;
            }, attempts: 3);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('status', $domainChanged ? 'Settings saved. Verify the new domain before collection resumes.' : 'Website settings saved.');
    }

    public function destroy(Request $request, Site $site, AnalyticsSiteDeletionService $deletions): RedirectResponse
    {
        $this->authorize('delete', $site);
        $confirmation = $request->validate(['confirmation' => ['required', 'string', 'max:255']])['confirmation'];
        $requester = $request->user();
        abort_unless($requester instanceof Authenticatable, 401);
        $outcome = $deletions->request($requester, $site, (string) $confirmation);

        return $outcome->completed()
            ? to_route('analytics.dashboard')->with('status', 'Website deleted.')
            : to_route('analytics.sites.deletion-status', $outcome->requestId);
    }

    public function deletionStatus(Request $request, string $requestId, AnalyticsSiteDeletionService $deletions): View
    {
        $requester = $request->user();
        abort_unless($requester instanceof Authenticatable, 401);
        $outcome = $deletions->status($requestId, $requester);

        return view('analytics::sites.deletion', compact('outcome'));
    }

    public function retryDeletion(Request $request, string $requestId, AnalyticsSiteDeletionService $deletions): RedirectResponse
    {
        $requester = $request->user();
        abort_unless($requester instanceof Authenticatable, 401);
        $outcome = $deletions->retry($requestId, $requester);
        if ($outcome->completed()) {
            return to_route('analytics.dashboard')->with('status', 'Website deleted.');
        }

        return to_route('analytics.sites.deletion-status', $outcome->requestId);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return trim((string) strtok($domain, '/'));
    }
}
