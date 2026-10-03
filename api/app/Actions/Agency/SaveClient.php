<?php

declare(strict_types=1);

namespace App\Actions\Agency;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveClient
{
    /**
     * Add or change a client: their name, the account's projects done for them, who gets their monthly report, and
     * the markup on costs passed on.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  array{name: string, emails: string, project_ids: list<string>, markup_percent: int, monthly_report: bool}  $data
     * @param  Client|null  $client  the one to change, or null for a new one
     * @return Client
     */
    public function handle(User $actor, Account $account, array $data, ?Client $client = null): Client
    {
        Gate::forUser($actor)->authorize('update', $account);
        abort_if($client !== null && $client->account_id !== $account->id, 404);
        $emails = array_values(array_unique(array_filter(array_map(fn (string $email): string => mb_strtolower(trim($email)), preg_split('/[\s,;]+/', $data['emails']) ?: []))));
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['emails' => __(':email isn’t an email address.', ['email' => $email])]);
            }
        }
        if (count($emails) > 5) {
            throw ValidationException::withMessages(['emails' => __('Up to five addresses.')]);
        }
        $projects = Project::query()->where('account_id', $account->id)->whereIn('id', $data['project_ids'])->pluck('id')->all();
        $client ??= new Client;
        $client->forceFill([
            'account_id' => $account->id, 'name' => trim($data['name']), 'emails' => $emails, 'project_ids' => array_values($projects),
            'markup_percent' => max(0, min(500, $data['markup_percent'])), 'monthly_report' => $data['monthly_report'],
        ])->save();

        return $client;
    }
}
