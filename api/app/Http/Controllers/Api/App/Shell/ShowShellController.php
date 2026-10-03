<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App\Shell;

use App\Data\Billing\LimitUsage;
use App\Http\View\NavLink;
use App\Http\View\ShellComposer;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\PlanUsage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/shell/{service?}` and `GET /api/app/projects/{project}/shell/{service?}`. */
final class ShowShellController
{
    /**
     * Return the signed-in frame the Next.js app draws around its pages: the person, their accounts and projects, the
     * navigation for the project and service in the URL, badges, and the language to use.
     *
     * @param  User  $user
     * @param  ShellComposer  $composer  Builds the same shell the Blade pages use; it reads the project from the route.
     * @param  PlanUsage  $planUsage
     * @param  Project|null  $project  The project in the URL, bound so the project middleware can check access.
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, ShellComposer $composer, PlanUsage $planUsage, ?Project $project = null): JsonResponse
    {
        $shell = $composer->shell($user);
        $links = fn (array $navLinks): array => array_map(fn (NavLink $link): array => ['label' => $link->label, 'url' => $link->url, 'icon' => $link->icon], $navLinks);

        return response()->json([
            'locale' => app()->getLocale(),
            'user' => ['name' => $user->name, 'email' => $user->email, 'isPlatformAdmin' => (bool) $user->is_platform_admin],
            'account' => $shell->account === null ? null : ['id' => $shell->account->id, 'name' => $shell->account->name],
            'accounts' => $shell->accounts,
            'project' => $shell->project === null ? null : ['id' => $shell->project->id, 'name' => $shell->project->name],
            'projects' => $shell->projects,
            'primaryNav' => $links($shell->primaryNav),
            'sectionLabel' => $shell->sectionLabel,
            'sectionNav' => $links($shell->sectionNav),
            'accountLinks' => $links($shell->accountLinks),
            'canCreateProject' => $shell->canCreateProject,
            'unreadNotifications' => $shell->unreadNotifications,
            'unseenChanges' => $shell->unseenChanges,
            'platformOperational' => $shell->platformOperational,
            'limitWarning' => $shell->limitWarning === null ? null : $this->limitWarning($shell->limitWarning, $planUsage),
            'links' => [
                'dashboard' => route('dashboard'), 'settings' => route('settings.profile'), 'help' => route('help'), 'changelog' => route('changelog'),
                'notifications' => route('notifications.index'), 'logout' => route('logout'), 'status' => route('platform.status'),
            ],
        ]);
    }

    /**
     * Describe a nearly used-up plan limit as the banner shows it, with the link to upgrade.
     *
     * @param  LimitUsage  $near
     * @param  PlanUsage  $planUsage
     * @return array{tone: string, message: string, linkLabel: string, url: string}
     */
    private function limitWarning(LimitUsage $near, PlanUsage $planUsage): array
    {
        $monthly = $near->monthly ? __(' this month') : '';
        $message = $near->percent() >= 100
            ? __('You’ve reached your plan’s limit of :limit :label:monthly.', ['limit' => number_format((int) $near->limit), 'label' => $near->label, 'monthly' => $monthly])
            : __('You’ve used :used of :limit :label on your plan:monthly.', ['used' => number_format($near->used), 'limit' => number_format((int) $near->limit), 'label' => $near->label, 'monthly' => $monthly]);
        $upgrade = $planUsage->upgradeFor($near);
        if ($upgrade !== null) {
            $message .= ' '.__(':service :tier gives :limit :label for $:price a month.', [
                'service' => $upgrade['service'], 'tier' => $upgrade['tier']->name, 'limit' => $upgrade['limit'] === null ? __('unlimited') : number_format($upgrade['limit']),
                'label' => $near->label, 'price' => number_format($upgrade['monthlyCents'] / 100, $upgrade['monthlyCents'] % 100 === 0 ? 0 : 2),
            ]);
        }

        return [
            'tone' => $near->percent() >= 100 ? 'danger' : 'warning',
            'message' => $message,
            'linkLabel' => $upgrade !== null ? __('Upgrade') : __('See plans'),
            'url' => route('account.billing', ['tab' => $near->service]),
        ];
    }
}
