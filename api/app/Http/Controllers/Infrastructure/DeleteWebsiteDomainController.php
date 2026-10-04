<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteWebsiteDomain;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteWebsiteDomainController
{
    /**
     * Remove a domain from a website.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $domain
     * @param  DeleteWebsiteDomain  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $domain, DeleteWebsiteDomain $delete): JsonResponse
    {
        $delete->handle($project->account, $user, $website->domains()->findOrFail((int) $domain));

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains'], false), 'message' => __('Domain removed.')]);
    }
}
