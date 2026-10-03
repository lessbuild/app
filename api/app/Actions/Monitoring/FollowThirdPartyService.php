<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Exceptions\AccountRuleViolation;
use App\Jobs\Monitoring\CheckThirdPartyStatus;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ThirdPartyService;
use App\Models\User;
use App\Services\Monitoring\PublicHttpTarget;
use App\Support\Monitoring\StatusProviders;
use Illuminate\Support\Facades\Gate;

final class FollowThirdPartyService
{
    /**
     * How many services one project can follow.
     *
     * @var int
     */
    public const MAX_PER_PROJECT = 25;

    /**
     * Create a new FollowThirdPartyService instance.
     *
     * @param  PublicHttpTarget  $targets  Checks custom addresses are public.
     */
    public function __construct(private readonly PublicHttpTarget $targets) {}

    /**
     * Follow a service's public status: one from the list, or any Statuspage-style status page by its https address.
     * The first check is queued straight away.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $provider  a key of StatusProviders::LIST, or custom
     * @param  string|null  $name  for a custom status page
     * @param  string|null  $url  for a custom status page
     * @return ThirdPartyService
     */
    public function handle(User $actor, Project $project, string $provider, ?string $name, ?string $url): ThirdPartyService
    {
        Gate::forUser($actor)->authorize('create', [Monitor::class, $project]);
        if (ThirdPartyService::query()->where('project_id', $project->id)->count() >= self::MAX_PER_PROJECT) {
            throw new AccountRuleViolation('provider', __('A project can follow up to :count services.', ['count' => self::MAX_PER_PROJECT]));
        }
        if ($provider === 'custom') {
            $url = rtrim(trim((string) $url), '/');
            $name = trim((string) $name);
            if ($name === '' || ! str_starts_with($url, 'https://') || $this->targets->parse($url) === null || parse_url($url, PHP_URL_PATH) !== null) {
                throw new AccountRuleViolation('url', __('Give it a name and the status page’s https:// address, without a path.'));
            }
        } elseif (isset(StatusProviders::LIST[$provider])) {
            ['name' => $name, 'url' => $url] = StatusProviders::LIST[$provider];
        } else {
            throw new AccountRuleViolation('provider', __('Choose a service from the list.'));
        }
        if (ThirdPartyService::query()->where('project_id', $project->id)->where('url', $url)->exists()) {
            throw new AccountRuleViolation('provider', __('This project already follows :name.', ['name' => $name]));
        }
        $service = new ThirdPartyService;
        $service->forceFill(['project_id' => $project->id, 'provider' => $provider, 'name' => mb_substr($name, 0, 80), 'url' => $url])->save();
        CheckThirdPartyStatus::dispatch($url)->afterCommit();

        return $service;
    }
}
