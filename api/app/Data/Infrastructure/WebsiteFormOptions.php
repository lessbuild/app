<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use App\Models\Account;
use App\Models\Environment;
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
     */
    public function __construct(
        public array $hosts,
        public array $environments,
        public array $healthIntervals,
        public array $failureThresholds,
    ) {}

    /**
     * Find the choices for an account's websites.
     *
     * @param  WebsitesQuery  $websites
     * @param  Account  $account
     * @return self
     */
    public static function for(WebsitesQuery $websites, Account $account): self
    {
        return new self(
            hosts: array_values($websites->hosts($account->id)->map(fn (Server $host): array => ['value' => (string) $host->id, 'label' => $host->label().' · '.$host->public_ip])->all()),
            environments: array_values($websites->environments($account)->map(fn (Environment $environment): array => ['value' => (string) $environment->id, 'label' => $environment->project->name.' / '.$environment->name])->all()),
            healthIntervals: Website::HEALTH_CHECK_INTERVALS,
            failureThresholds: Website::HEALTH_FAILURE_THRESHOLDS,
        );
    }
}
