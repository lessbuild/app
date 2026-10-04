<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Services\Billing\Entitlements;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/clients`. */
final class ShowClientsController
{
    /**
     * Return the account's clients, the projects they can be given, its white-label branding and whether the plan
     * includes it.
     *
     * @param  Account  $account
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Entitlements $entitlements): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'clients' => Client::query()->where('account_id', $account->id)->orderBy('name')->get()->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'emails' => $client->emails,
                'projectIds' => $client->project_ids,
                'markupPercent' => $client->markup_percent,
                'monthlyReport' => (bool) $client->monthly_report,
            ])->values(),
            'projects' => Project::query()->where('account_id', $account->id)->where('is_sample', false)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->values(),
            'branding' => ['name' => $account->brand_name, 'color' => $account->brand_color, 'logoUrl' => $account->brand_logo_url],
            'whiteLabel' => $entitlements->for($account)->has('account.white_label'),
        ]);
    }
}
