<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AccountRole;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Telemetry\IssueDigest;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Which emails you get from the current account. */
final class ShowNotificationSettingsController
{
    /**
     * Show the email settings page, offering the issue digest to members who use Monitoring on a plan that includes
     * it.
     *
     * @param  User  $user
     * @param  IssueDigest  $digest
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, IssueDigest $digest, Entitlements $entitlements): View
    {
        $account = $user->currentAccount;
        $membership = $account?->memberships()->where('user_id', $user->id)->first();

        return view('settings.notifications', [
            'account' => $account,
            'digestEnabled' => $account !== null && $digest->wants($account, $user),
            'digestAvailable' => $account !== null && $membership !== null && $membership->role !== AccountRole::Viewer && $membership->canUseService('monitoring')
                && $entitlements->for($account)->has('monitoring.issue_digest'),
        ]);
    }
}
