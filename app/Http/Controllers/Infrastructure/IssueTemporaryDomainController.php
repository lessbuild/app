<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveWebsiteDomain;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Gives a website a random hostname under TEMPORARY_APP_DOMAIN, via one of the account's Cloudflare providers. */
final class IssueTemporaryDomainController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, SaveWebsiteDomain $save): RedirectResponse
    {
        $base = strtolower(trim((string) config('infrastructure.temporary_base_domain')));
        abort_if($base === '', 404);
        $validated = $request->validate(['dns_provider_id' => ['required', 'integer', 'min:1']]);
        [$domain, $warning] = $save->handle($project->account, $user, $website, [
            'hostname' => Str::limit($website->deployment_slug, 40, '').'-'.Str::lower(Str::random(8)).'.'.$base,
            'type' => 'alias', 'dns_provider_id' => (int) $validated['dns_provider_id'], 'is_temporary' => true,
        ]);

        return to_route('infrastructure.websites.show', [$project, $website->id])->with($warning === null ? 'status' : 'notice', $warning ?? __(':domain added.', ['domain' => $domain->hostname]));
    }
}
