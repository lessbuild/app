<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Models\User;
use Illuminate\Contracts\View\View;

final class StopGettingStartedEmailsController
{
    /**
     * Turn getting-started emails off for the person the signed link belongs to.
     *
     * @param  string  $user
     * @return View
     */
    public function __invoke(string $user): View
    {
        $person = User::query()->findOrFail($user);
        $person->forceFill(['getting_started_emails' => false])->save();

        return view('onboarding.stop-emails', ['person' => $person, 'action' => null]);
    }
}
