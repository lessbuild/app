<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ConnectAdAccount;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChooseAdAccountController
{
    /**
     * Connect the ad account chosen after signing in to the platform, and read its spend.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ConnectAdAccount  $connect
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ConnectAdAccount $connect): JsonResponse
    {
        $accountId = (string) $request->validate(['account_id' => ['required', 'string', 'max:40']])['account_id'];
        $pending = $request->session()->get('ads.choose');
        abort_unless(is_array($pending) && ($pending['site'] ?? null) === $site->id && is_string($pending['credential'] ?? null) && is_string($pending['platform'] ?? null), 410);
        $account = $connect->handle($user, $site, $pending['platform'], (string) decrypt($pending['credential']), $accountId);
        $request->session()->forget('ads.choose');

        return response()->json(['redirect' => route('analytics.campaigns', [$project, 'site' => $site->id], false), 'message' => $account->error === null ? __(':account is connected and its spend is read daily.', ['account' => $account->name]) : __(':account is connected, but reading its spend failed: :error', ['account' => $account->name, 'error' => $account->error])]);
    }
}
