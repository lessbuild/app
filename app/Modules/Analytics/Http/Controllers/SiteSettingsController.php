<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    public function edit(Site $site): View
    {
        $this->authorize('manage', $site);

        return view('analytics::sites.settings', compact('site'));
    }

    public function update(Request $request, Site $site): RedirectResponse
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

        if ($site->events()->exists() && $validated['timezone'] !== $site->timezone) {
            return back()->withErrors(['timezone' => 'The reporting timezone cannot change after collection begins.'])->withInput();
        }

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

        $domainChanged = $domains !== ($site->domains ?? []);
        $site->update([
            'name' => $validated['name'],
            'domains' => $domains,
            'timezone' => $validated['timezone'],
            'excluded_paths' => $excludedPaths,
            'collection_enabled' => (bool) ($validated['collection_enabled'] ?? false),
            'collection_paused_at' => ($validated['collection_paused'] ?? false) ? now() : null,
            'verified_at' => $domainChanged ? null : $site->verified_at,
        ]);

        return back()->with('status', $domainChanged ? 'Settings saved. Verify the new domain before collection resumes.' : 'Website settings saved.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $this->authorize('delete', $site);
        DB::connection('analytics')->transaction(function () use ($site): void {
            $site->update(['collection_enabled' => false, 'collection_paused_at' => now()]);
            $site->events()->delete();
            $site->releaseAnnotations()->delete();
            $site->incidentAnnotations()->delete();
            $site->visits()->delete();
            $site->goals()->delete();
            $site->ingestionBatches()->delete();
            $site->delete();
        });

        return to_route('analytics.dashboard')->with('status', 'Website deleted.');
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return trim((string) strtok($domain, '/'));
    }
}
