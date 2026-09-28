<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CreateDatabaseUser;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreDatabaseUserController
{
    /**
     * Add an extra database user and shows its password once.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  CreateDatabaseUser  $create
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, CreateDatabaseUser $create): RedirectResponse
    {
        /** @var array{username: string, privilege: string, expires_in_days?: string|null} $data */
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/\A[a-zA-Z][a-zA-Z0-9_]*\z/'],
            'privilege' => ['required', 'in:read,write,admin'],
            'expires_in_days' => ['nullable', 'integer', 'in:1,7,30,90'],
        ]);
        [$databaseUser, $password] = $create->handle($user, $website, $data);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'])
            ->with('secrets', ['database_user' => $password, 'database_user_name' => $databaseUser->username])
            ->with('status', __('Database user added.'));
    }
}
