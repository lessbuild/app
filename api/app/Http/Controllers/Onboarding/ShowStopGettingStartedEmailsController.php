<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowStopGettingStartedEmailsController
{
    /**
     * Ask to confirm turning getting-started emails off, from the signed link in one of them. Mail scanners follow
     * links, so the change itself needs the button.
     *
     * @param  Request  $request
     * @param  string  $user
     * @return View
     */
    public function __invoke(Request $request, string $user): View
    {
        return view('onboarding.stop-emails', ['person' => User::query()->findOrFail($user), 'action' => $request->fullUrl()]);
    }
}
