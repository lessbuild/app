<?php

declare(strict_types=1);

namespace App\Queries\Accounts;

use App\Models\Account;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Support\SpreadsheetCell;

/**
 * The account's inventories as spreadsheet rows (servers, websites, providers, recipes and repositories), as
 * Deployer exported them, limited to what the person may see. Secrets never appear, and text that a spreadsheet
 * would run as a formula is neutralised.
 */
final class InventoryQuery
{
    /** The inventories that can be exported. */
    public const array KINDS = ['servers', 'websites', 'providers', 'recipes', 'repositories'];

    /**
     * Build one inventory: its header row and rows.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $kind
     * @return array{header: list<string>, rows: list<list<string|int|null>>}
     */
    public function handle(Account $account, User $user, string $kind): array
    {
        $rows = match ($kind) {
            'servers' => $this->servers($account, $user),
            'websites' => $this->websites($account, $user),
            'providers' => $this->providers($account, $user),
            'recipes' => $this->recipes($account, $user),
            default => $this->repositories($account, $user),
        };

        return ['header' => array_values($rows['header']), 'rows' => array_values(array_map(fn (array $row): array => array_values(array_map(
            fn (string|int|null $cell): string|int|null => is_string($cell) ? SpreadsheetCell::text($cell) : $cell, $row,
        )), $rows['rows']))];
    }

    /**
     * List the servers.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return array{header: array<int, string>, rows: array<array-key, array<int, string|int|null>>}
     */
    private function servers(Account $account, User $user): array
    {
        $servers = Server::query()->where('account_id', $account->id)->with('provider')->withCount('websites')->orderBy('name')->get()
            ->filter(fn (Server $server): bool => $user->can('view', $server));

        return [
            'header' => ['Server ID', 'Name', 'Cloud identifier', 'Type', 'Region', 'Size', 'Image', 'Public IP', 'Private IP', 'Provider', 'Provider type', 'Status', 'Website count', 'Monthly cost', 'Currency', 'Created at'],
            'rows' => $servers->map(fn (Server $server): array => [
                $server->id, $server->label(), $server->identifier, $server->type->value, $server->region, $server->size, $server->image,
                $server->public_ip, $server->private_ip, $server->provider?->name, $server->provider?->type->label(), $server->provisioning_status,
                (int) $server->websites_count, $server->monthly_cost !== null ? number_format($server->monthly_cost, 2, '.', '') : null,
                $server->monthly_cost_currency, $server->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * List the websites.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return array{header: array<int, string>, rows: array<array-key, array<int, string|int|null>>}
     */
    private function websites(Account $account, User $user): array
    {
        $websites = Website::query()->where('account_id', $account->id)->with('server')->orderBy('name')->get()
            ->filter(fn (Website $website): bool => $user->can('view', $website));

        return [
            'header' => ['Website ID', 'Name', 'Domain', 'Description', 'Server', 'Provisioning status', 'Health check', 'Automatic monitoring', 'Check interval minutes', 'Outage confirmation failures', 'Health status', 'Health failure count', 'Last health check at', 'Release retention', 'Created at'],
            'rows' => $websites->map(fn (Website $website): array => [
                $website->id, $website->name, $website->url, $website->description, $website->server?->label(), $website->provisioning_status,
                $website->health_check_enabled ? 'enabled' : 'disabled', $website->health_check_enabled ? ($website->health_monitoring_enabled ? 'enabled' : 'paused') : 'disabled',
                $website->health_check_interval_minutes, $website->health_failure_threshold, $website->health_status, $website->health_failure_count,
                $website->health_last_checked_at?->toIso8601String(), $website->release_retention, $website->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * List the connected providers, for people who manage the account.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return array{header: array<int, string>, rows: array<array-key, array<int, string|int|null>>}
     */
    private function providers(Account $account, User $user): array
    {
        $providers = $user->can('update', $account)
            ? Provider::query()->where('account_id', $account->id)->with(['servers:id,provider_id,name,display_name', 'repositories:id,provider_id,name'])->orderBy('name')->get()
            : collect();

        return [
            'header' => ['Provider ID', 'Name', 'Type', 'Description', 'Servers', 'Server count', 'Repositories', 'Repository count', 'Connection status', 'Automatic monitoring', 'Interval minutes', 'Failure threshold', 'Consecutive failures', 'Connection checked at'],
            'rows' => $providers->map(fn (Provider $provider): array => [
                $provider->id, $provider->name, $provider->type->label(), $provider->description,
                $provider->servers->map(fn (Server $server): string => $server->label())->implode('; '), $provider->servers->count(),
                $provider->repositories->pluck('name')->implode('; '), $provider->repositories->count(), $provider->connection_status,
                $provider->connection_monitoring_enabled ? 'enabled' : 'paused', $provider->connection_check_interval_minutes,
                $provider->connection_failure_threshold, $provider->connection_failure_count, $provider->connection_checked_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * List the account's recipe library, with the servers created with each.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return array{header: array<int, string>, rows: array<array-key, array<int, string|int|null>>}
     */
    private function recipes(Account $account, User $user): array
    {
        $recipes = $user->can('viewAny', Recipe::class) ? Recipe::query()->where('account_id', $account->id)->orderBy('name')->get() : collect();
        // Servers keep a snapshot of the recipes they were created with, by name.
        $used = [];
        foreach (Server::query()->where('account_id', $account->id)->whereNotNull('recipe_snapshot')->get(['id', 'name', 'display_name', 'recipe_snapshot']) as $server) {
            foreach ($server->recipe_snapshot ?? [] as $recipe) {
                $used[$recipe['name']][] = $server->label();
            }
        }

        return [
            'header' => ['Recipe ID', 'Name', 'Description', 'Category', 'Published in the gallery', 'Installs', 'From the gallery', 'Servers created with it', 'Server count', 'Created at', 'Updated at'],
            'rows' => $recipes->map(fn (Recipe $recipe): array => [
                $recipe->id, $recipe->name, $recipe->description, $recipe->category->value, $recipe->is_published ? 'yes' : 'no', $recipe->install_count,
                $recipe->source_recipe_id !== null ? 'yes' : 'no', implode('; ', $used[$recipe->name] ?? []), count($used[$recipe->name] ?? []),
                $recipe->created_at?->toIso8601String(), $recipe->updated_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * List the repositories in the account's projects, with each one's latest deploy.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return array{header: array<int, string>, rows: array<array-key, array<int, string|int|null>>}
     */
    private function repositories(Account $account, User $user): array
    {
        $repositories = Repository::query()->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'))
            ->with(['provider', 'website.server'])->orderBy('name')->get()->filter(fn (Repository $repository): bool => $user->can('view', $repository));
        $latest = Build::query()->whereIn('id', Build::query()->whereIn('repository_id', $repositories->modelKeys())->selectRaw('MAX(id)')->groupBy('repository_id'))
            ->get()->keyBy('repository_id');

        return [
            'header' => ['Repository ID', 'Name', 'URL', 'Branch', 'Provider', 'Provider type', 'Website', 'Website domain', 'Server', 'Latest deployment status', 'Latest revision', 'Latest deployment at', 'Webhook enabled', 'Previews enabled'],
            'rows' => $repositories->map(fn (Repository $repository): array => [
                $repository->id, $repository->name, $repository->url, $repository->branch, $repository->provider?->name, $repository->provider?->type->label(),
                $repository->website->name, $repository->website->url, $repository->website->server?->label(),
                $latest->get($repository->id)?->status, $latest->get($repository->id)?->revision, $latest->get($repository->id)?->created_at?->toIso8601String(),
                $repository->webhook_enabled ? 'yes' : 'no', $repository->previews_enabled ? 'yes' : 'no',
            ])->all(),
        ];
    }
}
