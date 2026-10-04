<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AccountRole;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\WebPush;
use App\Services\Telemetry\IssueDigest;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/settings/notifications`. */
final class ShowNotificationSettingsController
{
    /**
     * Return the person's email and push preferences: the issue digest (when their plan and role offer it), the
     * getting-started and weekly emails, and the devices that receive push notifications.
     *
     * @param  User  $user
     * @param  IssueDigest  $digest
     * @param  Entitlements  $entitlements
     * @param  WebPush  $push
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, IssueDigest $digest, Entitlements $entitlements, WebPush $push): JsonResponse
    {
        $account = $user->currentAccount;
        $membership = $account?->memberships()->where('user_id', $user->id)->first();

        return response()->json([
            'account' => $account === null ? null : ['id' => $account->id, 'name' => $account->name],
            'digestAvailable' => $account !== null && $membership !== null && $membership->role !== AccountRole::Viewer && $membership->canUseService('monitoring')
                && $entitlements->for($account)->has('monitoring.issue_digest'),
            'digestEnabled' => $account !== null && $digest->wants($account, $user),
            'gettingStartedEmails' => $user->getting_started_emails,
            'weeklyReportEmails' => $user->weekly_report_emails,
            'pushKey' => $push->publicKey(),
            'pushDevices' => PushSubscription::query()->where('user_id', $user->id)->latest('id')->get()->map(fn (PushSubscription $device): array => [
                'id' => $device->id,
                'device' => $device->device,
                'createdAt' => $device->created_at?->toIso8601String(),
                'lastUsedAt' => $device->last_used_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
