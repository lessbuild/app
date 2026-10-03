<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusPageDomain;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateStatusPageDomainController
{
    /**
     * Set or clear the status page's custom domain and return to the page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  SaveStatusPageDomain  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, StatusPage $page, SaveStatusPageDomain $save): RedirectResponse
    {
        $request->validate(['custom_domain' => ['nullable', 'string', 'max:253']]);
        $save->handle($user, $page, $request->string('custom_domain')->toString());

        return to_route('monitoring.status-pages.show', [$project, $page->id])
            ->with('status', $page->custom_domain === null ? __('Custom domain removed.') : __('Custom domain saved. Add the DNS records below, then check them.'));
    }
}
