<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\UpdateWebsiteCaddyDirectives;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateWebsiteCaddyDirectivesController
{
    /**
     * Save a website's own web server directives and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  UpdateWebsiteCaddyDirectives  $update
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, UpdateWebsiteCaddyDirectives $update): RedirectResponse
    {
        $update->handle($user, $website, is_string($request->input('caddy_directives')) ? $request->input('caddy_directives') : null);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'settings'])
            ->with('status', __('Saved. Caddy checks the configuration before using it; if it refuses, the old one stays and the reason shows here.'));
    }
}
