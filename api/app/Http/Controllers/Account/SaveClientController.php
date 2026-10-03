<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Agency\SaveClient;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SaveClientController
{
    /**
     * Add a client, or change one when the route names it.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveClient  $save
     * @param  Client|null  $client
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveClient $save, ?Client $client = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'emails' => ['nullable', 'string', 'max:1000'],
            'project_ids' => ['nullable', 'array'], 'project_ids.*' => ['string', 'max:26'], 'markup_percent' => ['nullable', 'integer', 'between:0,500'],
        ]);
        $save->handle($user, $account, [
            'name' => $data['name'], 'emails' => (string) ($data['emails'] ?? ''), 'project_ids' => array_values($data['project_ids'] ?? []),
            'markup_percent' => (int) ($data['markup_percent'] ?? 0), 'monthly_report' => $request->boolean('monthly_report'),
        ], $client);

        return to_route('account.clients')->with('status', __('Client saved.'));
    }
}
