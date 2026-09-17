<?php

namespace App\View\Navigation;

use App\Models\User;

/**
 * Builds the shared workspace navigation model for authenticated layouts.
 *
 * This is presentation data only. Authorization remains in the existing
 * organization and platform checks; the navigation simply avoids rendering
 * links that the current actor cannot use.
 */
final class WorkspaceNavigation
{
    /**
     * @return array{
     *     groups: list<array{label: string, mobile_expanded: bool, items: list<array<string, mixed>>}>,
     *     support: list<array<string, mixed>>,
     *     profile: list<array<string, mixed>>,
     *     unread_notifications: int,
     * }
     */
    public function for(User $user): array
    {
        $unreadNotifications = $user->unreadNotifications()->count();

        $groups = [
            $this->group(__('Overview'), [
                $this->item(__('Dashboard'), 'dashboard', 'view-grid', ['dashboard']),
            ], true),
            $this->group(__('Build and release'), [
                $this->item(__('Applications'), 'projects.index', 'view-grid', [
                    'projects.*',
                    'environments.*',
                    'builds.*',
                    'repositories.*',
                ]),
            ], true),
            $this->group(__('Infrastructure'), [
                $this->item(__('Sites'), 'websites.index', 'link', ['websites.*']),
                $this->item(__('Servers'), 'servers.index', 'cloud', ['servers.*']),
                $this->item(__('Providers'), 'providers.index', 'share', ['providers.*']),
            ]),
            $this->group(__('Data and recovery'), [
                $this->item(__('Databases'), 'databases.index', 'database', ['databases.*']),
                $this->item(__('Backups'), 'backups.index', 'database', ['backups.*']),
            ]),
            $this->group(__('Traffic'), [
                $this->item(__('Domains and TLS'), 'domains.index', 'link', ['domains.*']),
                $this->item(__('High availability'), 'load-balancers.index', 'cloud', ['load-balancers.*']),
            ]),
            $this->group(__('Health and operations'), [
                $this->item(__('Observability'), 'observability.index', 'chip', ['observability.*']),
                $this->item(__('Commands'), 'commands.index', 'tasks', ['commands.*']),
                $this->item(__('Activity'), 'activity.index', 'clock', ['activity.*']),
                $this->item(__('Notifications'), 'notifications.index', 'information-circle', ['notifications.*'], $unreadNotifications),
            ]),
            $this->group(__('Automation'), [
                $this->item(__('Automation and API'), 'automation.index', 'terminal', ['automation.*']),
            ]),
            $this->group(__('Templates'), [
                $this->item(__('Template library'), 'recipes.index', 'terminal', ['recipes.*', 'gallery.*']),
            ]),
        ];

        $administrationItems = [];

        if ($user->currentOrganization?->permits($user, 'manage')) {
            $administrationItems[] = $this->item(__('System health'), 'system-health.index', 'chip', ['system-health.*']);
        }

        if ($user->isPlatformAdmin()) {
            $administrationItems[] = $this->item(__('Business analytics'), 'admin.analytics', 'view-grid', ['admin.analytics']);
            $administrationItems[] = $this->item(__('Access requests'), 'admin.access-requests.index', 'notification', ['admin.access-requests.*']);
        }

        if ($administrationItems !== []) {
            $groups[] = $this->group(__('Administration'), $administrationItems);
        }

        return [
            'groups' => $groups,
            'support' => [
                $this->item(__('Help and guides'), 'docs', 'information-circle', ['docs']),
                $this->item(__('Send feedback'), 'feedback.index', 'information-circle', ['feedback.*']),
            ],
            'profile' => [
                $this->item(__('Workspace'), 'organizations.index', 'user-circle', ['organizations.*']),
                $this->item(__('Billing and usage'), 'billing.index', 'information-circle', ['billing.*', 'costs.*']),
                $this->item(__('Account and security'), 'account.index', 'user-circle', ['account.*']),
            ],
            'unread_notifications' => $unreadNotifications,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{label: string, mobile_expanded: bool, items: list<array<string, mixed>>}
     */
    private function group(string $label, array $items, bool $mobileExpanded = false): array
    {
        return [
            'label' => $label,
            'mobile_expanded' => $mobileExpanded,
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $activePatterns
     * @return array<string, mixed>
     */
    private function item(
        string $label,
        string $route,
        string $icon,
        array $activePatterns,
        ?int $badge = null,
        ?string $anchor = null,
    ): array {
        return [
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'active' => $activePatterns,
            'badge' => $badge,
            'anchor' => $anchor,
        ];
    }
}
