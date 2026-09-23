<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($invitation->isPending(), 410, 'This invitation has expired.');

        return view('analytics::workspaces.invitation', compact('invitation', 'token'));
    }

    public function accept(Request $request, string $token, AnalyticsWorkspaceAccess $access): RedirectResponse
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($invitation->isPending(), 410, 'This invitation has expired.');
        abort_unless(strcasecmp($request->user()->email, $invitation->email) === 0, 403, 'This invitation belongs to another email address.');

        $productUserIds = $access->productUserIds($request->user());
        abort_if($productUserIds === [], 403, 'Analytics access is not yet reconciled for this account.');

        DB::connection('analytics')->transaction(function () use ($invitation, $productUserIds): void {
            $memberships = collect($productUserIds)->mapWithKeys(
                fn (string $productUserId): array => [$productUserId => ['role' => $invitation->role]],
            )->all();

            $invitation->workspace->users()->syncWithoutDetaching($memberships);
            $invitation->forceFill(['accepted_at' => now()])->save();
        });
        $request->session()->put('analytics_workspace_id', $invitation->workspace_id);

        return to_route('analytics.dashboard')->with('status', "You joined {$invitation->workspace->name}.");
    }
}
