<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateWeeklyReportEmailsController
{
    /**
     * Turn the Monday project report on or off.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->forceFill(['weekly_report_emails' => (bool) $request->validate(['weekly_report_emails' => ['required', 'boolean']])['weekly_report_emails']])->save();

        return to_route('settings.notifications')->with('status', __('Email preferences saved.'));
    }
}
