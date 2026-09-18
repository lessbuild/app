<?php

namespace App\View;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Supplies a useful browser title when an authenticated view does not provide
 * one explicitly. This is presentation metadata only; it does not authorize
 * or load a resource.
 */
final class PageTitle
{
    /**
     * @var array<string, string>
     */
    private const EXACT_TITLES = [
        'dashboard' => 'Dashboard',
        'docs' => 'Product guide',
        'api-docs' => 'Control plane API',
        'pricing' => 'Pricing',
        'access-request.create' => 'Request access',
        'organizations.index' => 'Workspace',
        'billing.index' => 'Billing',
        'account.index' => 'Account',
        'account.sign-ins.index' => 'Sign-in history',
        'system-health.index' => 'System health',
        'observability.index' => 'Observability',
        'notifications.index' => 'Notifications',
        'activity.index' => 'Activity',
        'commands.index' => 'Command center',
        'automation.index' => 'Automation and API',
        'backups.index' => 'Backups',
        'domains.index' => 'Domains and TLS',
        'databases.index' => 'Database operations',
        'costs.index' => 'Cost visibility',
        'feedback.index' => 'Product feedback',
        'load-balancers.index' => 'High availability',
        'projects.index' => 'Applications',
        'projects.create' => 'Create application',
        'projects.configuration.create' => 'Application configuration',
        'builds.index' => 'Deployment history',
        'providers.index' => 'Providers',
        'providers.create' => 'Connect provider',
        'repositories.index' => 'Repositories',
        'repositories.create' => 'Connect repository',
        'websites.index' => 'Websites',
        'websites.create' => 'Add website',
        'recipes.index' => 'Provisioning recipes',
        'recipes.create' => 'Create recipe',
        'gallery.index' => 'Community recipe gallery',
        'gallery.reports.index' => 'Community feedback inbox',
        'gallery.reports.mine' => 'My community reports',
        'search.index' => 'Search',
        'admin.analytics' => 'Business analytics',
        'admin.access-requests.index' => 'Access requests',
        'admin.github-app.setup' => 'GitHub App setup',
        'platform-status.show' => 'Platform status',
        'status.show' => 'Public status',
    ];

    /**
     * Return a title for the current named route.
     */
    public function for(?Route $route): ?string
    {
        $name = $route?->getName();

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (isset(self::EXACT_TITLES[$name])) {
            return self::EXACT_TITLES[$name];
        }

        if (Str::startsWith($name, 'projects.configuration.')) {
            return 'Application configuration';
        }

        if ($name === 'observability.environments.context') {
            return 'Environment evidence';
        }

        $resource = $this->resourceLabel($name);

        return match (Str::afterLast($name, '.')) {
            'create' => 'Create '.$resource,
            'edit' => 'Edit '.$resource,
            'show' => $this->resourceTitle($route, $resource),
            'compare' => 'Compare '.$resource,
            'export' => $resource.' export',
            default => $resource,
        };
    }

    /**
     * @return array<string, string>
     */
    private function resourceLabels(): array
    {
        return [
            'account' => 'Account',
            'activity' => 'Activity',
            'admin' => 'Administration',
            'automation' => 'Automation',
            'backups' => 'Backups',
            'billing' => 'Billing',
            'builds' => 'Deployments',
            'commands' => 'Commands',
            'costs' => 'Cost visibility',
            'databases' => 'Databases',
            'domains' => 'Domains and TLS',
            'environments' => 'Environments',
            'feedback' => 'Product feedback',
            'gallery' => 'Gallery',
            'load-balancers' => 'High availability',
            'notifications' => 'Notifications',
            'observability' => 'Observability',
            'organizations' => 'Workspace',
            'projects' => 'Applications',
            'providers' => 'Providers',
            'recipes' => 'Recipes',
            'repositories' => 'Repositories',
            'servers' => 'Servers',
            'websites' => 'Websites',
        ];
    }

    private function resourceLabel(string $routeName): string
    {
        $root = Str::before($routeName, '.');

        return $this->resourceLabels()[$root] ?? Str::headline($root);
    }

    private function resourceTitle(?Route $route, string $fallback): string
    {
        $root = Str::before($route?->getName() ?? '', '.');
        $parameter = match ($root) {
            'builds' => 'build',
            'environments' => 'environment',
            'gallery' => 'recipe',
            'projects' => 'project',
            'providers' => 'provider',
            'repositories' => 'repository',
            'servers' => 'server',
            'websites' => 'website',
            default => null,
        };

        $resource = $parameter ? $route?->parameter($parameter) : null;

        if (is_object($resource)) {
            foreach (['name', 'label', 'title'] as $property) {
                $value = $resource->{$property} ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $fallback;
    }
}
