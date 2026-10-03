<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\InviteMember;
use App\Enums\AccountRole;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use App\Queries\Accounts\MembersOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/members`. */
final class ShowMembersController
{
    /**
     * Create a new ShowMembersController instance.
     *
     * @param  ServiceRegistry  $services  The platform's services, for limiting a member to some of them.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Return the account's members and pending invitations, what the viewer may change, how long an invitation lasts,
     * and the roles, services and projects a member can be given.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  MembersOverviewQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, MembersOverviewQuery $query): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'overview' => $query->handle($account, $user),
            'invitationDays' => InviteMember::EXPIRES_AFTER_DAYS,
            'roles' => array_map(fn (AccountRole $role): array => ['value' => $role->value, 'label' => $role->label(), 'description' => $role->description()], AccountRole::cases()),
            'services' => array_map(fn (PlatformService $service): array => ['key' => $service->key(), 'name' => $service->name()], array_values($this->services->all())),
            'projects' => Project::query()->where('account_id', $account->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->values(),
        ]);
    }
}
