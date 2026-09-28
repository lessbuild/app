<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CopyWebsiteDatabase;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CopyWebsiteDatabaseController
{
    /**
     * Copies this website's database over another website's on the same server, once the person has typed the
     * confirmation.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  CopyWebsiteDatabase  $copy
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, CopyWebsiteDatabase $copy): RedirectResponse
    {
        $request->validate(['target_website_id' => ['required', 'integer'], 'confirmation' => ['required', 'string', 'max:120']]);
        $target = Website::query()->where('account_id', $website->account_id)->findOrFail($request->integer('target_website_id'));
        $copy->handle($user, $website, $target, $request->string('confirmation')->toString());

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'])->with('status', __('Copying the database into :website.', ['website' => $target->name]));
    }
}
