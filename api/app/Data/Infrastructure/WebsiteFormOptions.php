<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use App\Models\Account;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Website;
use App\Queries\Infrastructure\WebsitesQuery;

final readonly class WebsiteFormOptions
{
    /**
     * Create a new WebsiteFormOptions instance.
     *
     * The choices on a website's form.
     *
     * @param  list<array{value: string, label: string}>  $hosts  Active app servers with MySQL, as "web-1 · 203.0.113.10".
     * @param  list<array{value: string, label: string}>  $environments  The account's environments, as "Shop / Production".
     * @param  list<int>  $healthIntervals  Minutes between health checks.
     * @param  list<int>  $failureThresholds  Failed checks before an incident.
     * @param  string|null  $defaultEnvironment  The environment a new website defaults to (the project's production one).
     */
    public function __construct(
        public array $hosts,
        public array $environments,
        public array $healthIntervals,
        public array $failureThresholds,
        public ?string $defaultEnvironment = null,
    ) {}

    /**
     * Find the choices for an account's websites.
     *
     * @param  WebsitesQuery  $websites
     * @param  Account  $account
     * @param  Project|null  $project  The project the form is for, used to default the environment.
     * @return self
     */
    public static function for(WebsitesQuery $websites, Account $account, ?Project $project = null): self
    {
        // Default a new website to the current project's production environment (else its first), so a website added
        // inside a project is linked to it and shows on the project's setup guide and overview.
        $default = $project?->environments()->orderByRaw("CASE WHEN kind = 'production' THEN 0 ELSE 1 END")->orderBy('id')->value('id');

        return new self(
            hosts: array_values($websites->hosts($account->id)->map(fn (Server $host): array => ['value' => (string) $host->id, 'label' => $host->label().' · '.$host->public_ip])->all()),
            environments: array_values($websites->environments($account)->map(fn (Environment $environment): array => ['value' => (string) $environment->id, 'label' => $environment->project->name.' / '.$environment->name])->all()),
            healthIntervals: Website::HEALTH_CHECK_INTERVALS,
            failureThresholds: Website::HEALTH_FAILURE_THRESHOLDS,
            defaultEnvironment: $default === null ? null : (string) $default,
        );
    }
}
