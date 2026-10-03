<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ChangeRuntimeVersion;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateWebsitePhpVersionController
{
    /**
     * Move the website to another PHP version and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  ChangeRuntimeVersion  $change
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, ChangeRuntimeVersion $change): RedirectResponse
    {
        $change->handle($user, $website, (string) $request->validate(['php_version' => ['required', 'string', 'max:4']])['php_version']);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'settings'])->with('status', __('Switching to PHP :version. It takes a few minutes the first time a version is installed.', ['version' => $website->php_version]));
    }
}
