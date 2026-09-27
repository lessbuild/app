<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\ManageDatabaseUser;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateDatabaseUser
{
    /**
     * Add a MySQL login on the website's database with a generated password, returned once for the person to copy.
     *
     * @param  array{username: string, privilege: string, expires_in_days?: int|string|null}  $data
     * @return array{DatabaseUser, string}
     */
    public function handle(User $actor, Website $website, array $data): array
    {
        Gate::forUser($actor)->authorize('manageDatabase', $website);
        $username = strtolower($data['username']);
        if (in_array($username, ['root', 'mysql', 'debian-sys-maint', $website->databaseIdentifier()], true)) {
            throw ValidationException::withMessages(['username' => __('That name is reserved.')]);
        }
        $password = Str::password(32, symbols: false);

        return DB::transaction(function () use ($actor, $website, $data, $username, $password): array {
            if (DatabaseUser::query()->where('website_id', $website->id)->where('username', $username)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['username' => __('This website already has a database user with that name.')]);
            }
            $user = new DatabaseUser;
            $user->forceFill([
                'website_id' => $website->id, 'created_by' => $actor->id, 'username' => $username, 'password' => $password, 'privilege' => $data['privilege'], 'status' => 'pending',
                'expires_at' => filled($data['expires_in_days'] ?? null) ? now()->addDays((int) $data['expires_in_days']) : null,
            ])->save();
            ManageDatabaseUser::dispatch($user->id, 'apply')->afterCommit();

            return [$user, $password];
        });
    }
}
