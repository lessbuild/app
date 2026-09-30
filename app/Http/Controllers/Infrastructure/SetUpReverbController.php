<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SetUpReverb;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class SetUpReverbController
{
    /**
     * Set up Laravel Reverb for a website and return to its settings, saying which port to put in .env.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  SetUpReverb  $setUp
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, SetUpReverb $setUp): RedirectResponse
    {
        $port = $setUp->handle($user, $website);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'settings'])
            ->with('status', __('Reverb is being set up on port :port. Set REVERB_SERVER_PORT=:port in the site’s .env and deploy.', ['port' => $port]));
    }
}
