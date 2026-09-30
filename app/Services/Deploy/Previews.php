<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\VerifiedRepositoryWebhook;
use App\Enums\EnvironmentKind;
use App\Jobs\Deploy\CleanUpPreview;
use App\Jobs\Deploy\DeployPreviewAfterDatabaseCopy;
use App\Jobs\Deploy\ReportPreviewToGitHub;
use App\Jobs\Infrastructure\CopyDatabase;
use App\Jobs\Infrastructure\ProvisionWebsite;
use App\Models\Account;
use App\Models\Build;
use App\Models\DatabaseClone;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use App\Services\Billing\AccountEntitlements;
use App\Services\Billing\Entitlements;
use App\Support\Deploy\PreviewTrust;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs previews from pull-request event to cleanup: makes a preview's stack, deploys each new revision once its website
 * is ready, follows its deploys, and closes and cleans it up. Nothing here acts for a person; the actions that do
 * (closing, approving secrets, retrying cleanup) authorise first and then call in.
 */
final class Previews
{
    /**
     * Create a new Previews instance.
     *
     * Runs the preview lifecycle.
     *
     * @param  Entitlements  $entitlements  Checks the plan allows previews and has room for another.
     * @param  Deployments  $deployments  Queues each preview deploy.
     * @param  BuildPayload  $payload  Captures what a preview deploy runs with.
     * @param  PreviewConfiguration  $configuration  Writes the preview website's `.env`.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Deployments $deployments,
        private readonly BuildPayload $payload,
        private readonly PreviewConfiguration $configuration,
    ) {}

    /**
     * Act on a verified pull-request event for the source repository: close the preview of a closed pull request, or
     * create, reopen or update the preview of an open one and deploy its revision.
     *
     * @param  Repository  $source
     * @param  VerifiedRepositoryWebhook  $webhook
     * @return string the preview's status, or why the event was ignored or refused
     */
    public function receive(Repository $source, VerifiedRepositoryWebhook $webhook): string
    {
        $existing = Preview::query()->where('source_repository_id', $source->id)->where('pull_request_number', $webhook->pullRequestNumber)->first();
        if ($webhook->previewAction === 'closed') {
            if ($existing === null) {
                return 'preview_not_found';
            }
            $this->close($existing);

            return Preview::STATUS_CLOSED;
        }
        $source->loadMissing(['project.account', 'provider', 'website', 'environment']);
        if (! $source->previews_enabled || $source->preview_domain === null) {
            return 'preview_ignored';
        }
        if (! $this->entitlements->for($source->project->account)->has('deploy.previews')) {
            return 'preview_plan_required';
        }
        $refusal = PreviewTrust::refusal($source, $webhook);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($webhook->revision === null || $webhook->sourceBranch === null || $webhook->pullRequestNumber === null) {
            return 'invalid_preview';
        }

        $preview = DB::transaction(fn (): ?Preview => $this->record($source, $webhook), attempts: 3);
        if ($preview === null) {
            return 'preview_limit_reached';
        }
        if ($preview->status === Preview::STATUS_DEPLOYING) {
            $this->deployLatest($preview);
        }
        ReportPreviewToGitHub::dispatch($preview->id);

        return $preview->refresh()->status;
    }

    /**
     * Open a preview of any branch of the source repository, deploying its latest commit, that closes at the expiry
     * date. Opening a branch that already has an open preview moves its expiry instead. Returns the preview, or why it
     * couldn't open: previews off, not on the plan, or no room.
     *
     * @param  Repository  $source
     * @param  string  $branch
     * @param  CarbonImmutable  $expiresAt
     * @return Preview|string
     */
    public function openBranch(Repository $source, string $branch, CarbonImmutable $expiresAt): Preview|string
    {
        $source->loadMissing(['project.account', 'provider', 'website', 'environment']);
        if (! $source->previews_enabled || $source->preview_domain === null) {
            return 'preview_ignored';
        }
        if (! $this->entitlements->for($source->project->account)->has('deploy.previews')) {
            return 'preview_plan_required';
        }
        $preview = DB::transaction(function () use ($source, $branch, $expiresAt): ?Preview {
            $account = Account::query()->lockForUpdate()->findOrFail($source->project->account_id);
            $open = Preview::query()->where('source_repository_id', $source->id)->whereNull('pull_request_number')->where('source_branch', $branch)
                ->where('status', '!=', Preview::STATUS_CLOSED)->lockForUpdate()->first();
            if ($open !== null) {
                $open->forceFill(['expires_at' => $expiresAt, 'last_activity_at' => now()])->save();

                return $open;
            }
            $entitlements = $this->entitlements->for($account);
            if (! $this->hasRoom($account, $entitlements, true)) {
                return null;
            }

            return $this->create($source, null, $branch, '', __('Branch :branch', ['branch' => $branch]), $entitlements, $expiresAt);
        }, attempts: 3);

        return $preview ?? 'preview_limit_reached';
    }

    /**
     * Deploy the preview's latest revision once its website has been set up.
     *
     * @param  Website  $website
     * @return void
     */
    public function websiteReady(Website $website): void
    {
        $preview = Preview::query()->where('website_id', $website->id)->first();
        if ($preview === null || ! $preview->isOpen()) {
            return;
        }
        if ($this->copyDatabaseFirst($preview, $website)) {
            return;
        }
        $this->deployLatest($preview);
    }

    /**
     * Start copying the chosen website's database into a new preview's, when its repository asks for that, with the
     * deploy queued behind it. Happens once per preview; returns whether the deploy now waits for the copy.
     *
     * @param  Preview  $preview
     * @param  Website  $website
     * @return bool
     */
    private function copyDatabaseFirst(Preview $preview, Website $website): bool
    {
        $sourceId = Repository::query()->whereKey($preview->source_repository_id)->value('preview_database_source_website_id');
        $source = $sourceId !== null ? Website::query()->whereKey((int) $sourceId)->first() : null;
        if ($preview->database_copied_at !== null || $source === null || $source->server_id !== $website->server_id || $source->is($website)) {
            return false;
        }
        if (Preview::query()->whereKey($preview->id)->whereNull('database_copied_at')->update(['database_copied_at' => now()]) === 0) {
            return true;
        }
        $clone = new DatabaseClone;
        $clone->forceFill(['source_website_id' => $source->id, 'target_website_id' => $website->id, 'requested_by' => null, 'status' => 'queued', 'mode' => $preview->sourceRepository->preview_database_mode, 'anonymise' => $preview->sourceRepository->preview_database_anonymise])->save();
        Bus::chain([new CopyDatabase($clone->id), new DeployPreviewAfterDatabaseCopy($preview->id)])->dispatch();

        return true;
    }

    /**
     * Mark the preview failed when its website couldn't be set up.
     *
     * @param  Website  $website
     * @return void
     */
    public function websiteFailed(Website $website): void
    {
        $preview = Preview::query()->where('website_id', $website->id)->first();
        if ($preview !== null && $preview->isOpen()) {
            $preview->forceFill(['status' => Preview::STATUS_FAILED])->save();
            ReportPreviewToGitHub::dispatch($preview->id);
        }
    }

    /**
     * Follow a finished deploy of a preview's repository. The initialisation command counts as done after a deploy that
     * ran it succeeds. A closed preview is cleaned up now that nothing is deploying; a newer revision that arrived
     * meanwhile is deployed; otherwise the preview is ready or failed.
     *
     * @param  Build  $build
     * @return void
     */
    public function buildFinished(Build $build): void
    {
        $preview = Preview::query()->where('repository_id', $build->repository_id)->first();
        if ($preview === null) {
            return;
        }
        if ($build->status === Build::STATUS_SUCCEEDED && isset($build->environment_payload['preview_initialization']) && $preview->initialized_at === null) {
            $preview->forceFill(['initialized_at' => now()])->save();
        }
        if (! $preview->isOpen()) {
            $this->cleanUpWhenIdle($preview);

            return;
        }
        if ($build->revision !== $preview->revision) {
            $this->deployLatest($preview);

            return;
        }
        $preview->forceFill(['status' => $build->status === Build::STATUS_SUCCEEDED ? Preview::STATUS_READY : Preview::STATUS_FAILED, 'last_activity_at' => now()])->save();
        ReportPreviewToGitHub::dispatch($preview->id);
    }

    /**
     * Close a preview: its approvals are revoked, the pull request is told, and its stack is removed once nothing is
     * deploying.
     *
     * @param  Preview  $preview
     * @return void
     */
    public function close(Preview $preview): void
    {
        $closed = DB::transaction(function () use ($preview): bool {
            $locked = Preview::query()->lockForUpdate()->findOrFail($preview->id);
            if (! $locked->isOpen()) {
                return false;
            }
            $locked->forceFill(['status' => Preview::STATUS_CLOSED, 'closed_at' => now()])->save();
            $locked->secretApprovals()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            return true;
        });
        if ($closed) {
            ReportPreviewToGitHub::dispatch($preview->id);
            $this->cleanUpWhenIdle($preview);
        }
    }

    /**
     * Queue the cleanup of a closed preview, unless it's already queued, running or done, or a deploy to its website
     * is still running (the deploy finishing calls back here). A failed cleanup is queued again.
     *
     * @param  Preview  $preview
     * @return bool whether cleanup was queued
     */
    public function cleanUpWhenIdle(Preview $preview): bool
    {
        return DB::transaction(function () use ($preview): bool {
            $locked = Preview::query()->lockForUpdate()->findOrFail($preview->id);
            if ($locked->isOpen() || in_array($locked->cleanup_status, [Preview::CLEANUP_QUEUED, Preview::CLEANUP_RUNNING, Preview::CLEANUP_SUCCEEDED], true)
                || ($locked->website_id !== null && Build::query()->where('website_id', $locked->website_id)->whereIn('status', Build::ACTIVE)->exists())) {
                return false;
            }
            $locked->forceFill(['cleanup_status' => Preview::CLEANUP_QUEUED, 'cleanup_error' => null])->save();
            CleanUpPreview::dispatch($locked->id)->afterCommit();

            return true;
        });
    }

    /**
     * Bring back the stack of a preview whose pull request reopened while it was being cleaned up, once cleanup has
     * finished: its website is restored and set up again.
     *
     * @param  Preview  $preview
     * @return void
     */
    public function reopenAfterCleanup(Preview $preview): void
    {
        DB::transaction(function () use ($preview): void {
            $locked = Preview::query()->lockForUpdate()->findOrFail($preview->id);
            if (! $locked->isOpen() || $locked->cleanup_status !== Preview::CLEANUP_SUCCEEDED) {
                return;
            }
            $locked->forceFill(['cleanup_status' => null, 'status' => Preview::STATUS_PROVISIONING])->save();
            if ($locked->website !== null) {
                $this->provision($locked, $locked->website);
            }
        });
    }

    /**
     * Rewrite the preview website's `.env` (after secrets are approved, say) and deploy the revision again with it.
     *
     * @param  Preview  $preview
     * @return void
     */
    public function reconfigure(Preview $preview): void
    {
        $website = $preview->website;
        if ($website === null || $website->trashed() || ! $preview->isOpen()) {
            return;
        }
        $website->forceFill(['env_file' => $this->configuration->environmentFile($preview, $website)])->save();
        $this->deployLatest($preview);
    }

    /**
     * Create, reopen or update the preview under an account lock, so concurrent events can't exceed the plan's
     * previews or websites. Returns null when the plan has no room.
     *
     * @param  Repository  $source
     * @param  VerifiedRepositoryWebhook  $webhook
     * @return Preview|null
     */
    private function record(Repository $source, VerifiedRepositoryWebhook $webhook): ?Preview
    {
        $account = Account::query()->lockForUpdate()->findOrFail($source->project->account_id);
        $preview = Preview::query()->where('source_repository_id', $source->id)->where('pull_request_number', $webhook->pullRequestNumber)->lockForUpdate()->first();
        $entitlements = $this->entitlements->for($account);
        if (($preview === null || ! $preview->isOpen()) && ! $this->hasRoom($account, $entitlements, $preview === null || $preview->website === null || $preview->website->trashed())) {
            return null;
        }
        if ($preview === null) {
            return $this->create($source, (int) $webhook->pullRequestNumber, (string) $webhook->sourceBranch, (string) $webhook->revision, $webhook->pullRequestTitle, $entitlements);
        }

        $reopening = ! $preview->isOpen();
        if ($preview->revision !== $webhook->revision) {
            $preview->secretApprovals()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        $preview->forceFill([
            'title' => $webhook->pullRequestTitle, 'source_branch' => (string) $webhook->sourceBranch, 'revision' => (string) $webhook->revision,
            'source_environment_id' => $source->environment_id, 'last_activity_at' => now(), 'closed_at' => null,
        ]);
        $preview->repository?->forceFill(['branch' => $webhook->sourceBranch])->save();
        $website = $preview->website;
        $cleaning = in_array($preview->cleanup_status, [Preview::CLEANUP_QUEUED, Preview::CLEANUP_RUNNING], true);
        if ($reopening && ! $cleaning) {
            $preview->cleanup_status = null;
        }
        $preview->status = match (true) {
            $website === null, $cleaning, $website->trashed(), $website->provisioning_status !== Website::STATUS_ACTIVE => Preview::STATUS_PROVISIONING,
            default => Preview::STATUS_DEPLOYING,
        };
        $preview->save();
        if ($website !== null && ! $cleaning) {
            $reopening && $website->trashed() ? $this->provision($preview, $website) : $website->forceFill(['env_file' => $this->configuration->environmentFile($preview, $website)])->save();
        }

        return $preview;
    }

    /**
     * Determine whether the plan has room for one more open preview and, when one is needed, one more website.
     *
     * @param  Account  $account
     * @param  AccountEntitlements  $entitlements
     * @param  bool  $needsWebsite
     * @return bool
     */
    private function hasRoom(Account $account, AccountEntitlements $entitlements, bool $needsWebsite): bool
    {
        $open = Preview::query()->whereHas('project', fn ($query) => $query->where('account_id', $account->id))->where('status', '!=', Preview::STATUS_CLOSED)->count();

        return $entitlements->allows('deploy.previews.max', $open + 1)->allowed
            && (! $needsWebsite || $entitlements->allows('deploy.websites.max', Website::query()->where('account_id', $account->id)->count() + 1)->allowed);
    }

    /**
     * Make a new preview's stack: a `preview` environment copying the source environment's runtime, processes and
     * managed caches (as the plan allows), a website on the source website's server, and a repository on the branch.
     * The website starts setting up after commit. Pull request previews carry the number and head commit; branch
     * previews deploy the branch's latest commit until they expire.
     *
     * @param  Repository  $source
     * @param  int|null  $number  the pull request, or null for a branch preview
     * @param  string  $branch
     * @param  string  $revision  the commit to deploy; empty for the branch's latest
     * @param  string|null  $title
     * @param  AccountEntitlements  $entitlements
     * @param  CarbonImmutable|null  $expiresAt  a branch preview's expiry
     * @return Preview
     */
    private function create(Repository $source, ?int $number, string $branch, string $revision, ?string $title, AccountEntitlements $entitlements, ?CarbonImmutable $expiresAt = null): Preview
    {
        $project = $source->project;
        $label = $number !== null ? "PR #{$number}" : "Branch {$branch}";
        $prefix = $number !== null ? "pr-{$number}" : substr('br-'.Str::slug(str_replace('/', '-', $branch)), 0, 40);
        $environment = $this->environment($project, $source->environment, $prefix, $label, $entitlements);

        $website = new Website;
        $website->forceFill([
            'account_id' => $project->account_id, 'server_id' => $source->website->server_id, 'environment_id' => $environment->id,
            'name' => Str::limit("{$project->name} {$label}", 250, ''), 'description' => __('Preview of :repository :label', ['repository' => $source->name, 'label' => $label]),
            'url' => $this->hostname($source, $prefix), 'database_password' => Str::random(32), 'provisioning_status' => Website::STATUS_QUEUED,
            'release_retention' => min(3, $source->website->release_retention), 'health_check_enabled' => false, 'health_monitoring_enabled' => false,
        ])->save();

        $repository = new Repository;
        $repository->forceFill([
            'project_id' => $project->id, 'provider_id' => $source->provider_id, 'website_id' => $website->id, 'environment_id' => $environment->id,
            'name' => Str::limit("{$source->name} {$label}", 120, ''), 'url' => $source->url, 'branch' => $branch,
            'deployment_root' => $source->deployment_root, 'build_commands' => $source->build_commands, 'post_deployment_commands' => $source->post_deployment_commands,
            'webhook_enabled' => false,
        ])->save();

        $preview = new Preview;
        $preview->forceFill([
            'project_id' => $project->id, 'source_repository_id' => $source->id, 'source_environment_id' => $source->environment_id,
            'environment_id' => $environment->id, 'website_id' => $website->id, 'repository_id' => $repository->id, 'pull_request_number' => $number,
            'title' => $title, 'source_branch' => $branch, 'revision' => $revision, 'expires_at' => $expiresAt,
            'status' => Preview::STATUS_PROVISIONING, 'url' => $website->url, 'last_activity_at' => now(),
        ])->save();
        $website->forceFill(['env_file' => $this->configuration->environmentFile($preview, $website)])->save();
        ProvisionWebsite::dispatch($website->id, (string) $website->provisioning_token)->afterCommit();

        return $preview;
    }

    /**
     * Create the preview's environment (`pr-{number}` or `br-{branch}`, with a suffix if taken) from the source environment's runtime
     * and its recipes (with their run-on-new-websites setting), plus, as the plan allows, its enabled processes and
     * managed Redis/Valkey. External resources are left out: their
     * variables point at the source's own services.
     *
     * @param  Project  $project
     * @param  Environment|null  $source
     * @param  string  $slug  such as pr-12 or br-feature-cart
     * @param  string  $name  such as PR #12 or Branch feature/cart
     * @param  AccountEntitlements  $entitlements
     * @return Environment
     */
    private function environment(Project $project, ?Environment $source, string $slug, string $name, AccountEntitlements $entitlements): Environment
    {
        $base = $slug;
        for ($suffix = 2; $project->environments()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }
        $environment = new Environment;
        $environment->forceFill([
            'project_id' => $project->id, 'name' => mb_substr($name, 0, 60), 'slug' => $slug, 'kind' => EnvironmentKind::Preview,
            ...($source === null ? [] : [
                'runtime_type' => $source->runtime_type, 'runtime_version' => $source->runtime_version, 'build_command' => $source->build_command,
                'start_command' => $source->start_command, 'container_port' => $source->container_port, 'dockerfile_path' => $source->dockerfile_path,
            ]),
        ])->save();
        if ($source === null) {
            return $environment;
        }
        foreach ($source->recipes()->get() as $recipe) {
            $copy = $recipe->replicate(['environment_id']);
            $copy->forceFill(['environment_id' => $environment->id])->save();
        }
        if ($environment->recipes()->exists()) {
            $environment->forceFill(['recipes_run_on_new_websites' => $source->recipes_run_on_new_websites])->save();
        }
        if ($entitlements->has('deploy.workers')) {
            foreach ($source->processes()->where('is_enabled', true)->get() as $process) {
                $copy = new EnvironmentProcess;
                $copy->forceFill([
                    'environment_id' => $environment->id, 'name' => $process->name, 'type' => $process->type, 'command' => $process->command,
                    'replicas' => 1, 'restart_policy' => $process->restart_policy, 'restart_delay_seconds' => $process->restart_delay_seconds, 'is_enabled' => true,
                ])->save();
            }
        }
        if ($entitlements->has('deploy.resources')) {
            foreach ($source->resources()->where('is_managed', true)->whereIn('type', ['redis', 'valkey'])->get() as $resource) {
                $copy = new EnvironmentResource;
                $copy->forceFill([
                    'environment_id' => $environment->id, 'name' => $resource->name, 'type' => $resource->type, 'is_managed' => true, 'status' => 'ready',
                    'configuration' => EnvironmentResource::managedCache($environment->id, $resource->type, $resource->name),
                ])->save();
            }
        }

        return $environment;
    }

    /**
     * Choose the preview's hostname: `pr-{number}-{project}.{domain}` (or `br-{branch}-…` for a branch), or with the
     * source repository's ID added when another repository's preview already has it.
     *
     * @param  Repository  $source
     * @param  string  $prefix  such as pr-12 or br-feature-cart
     * @return string
     */
    private function hostname(Repository $source, string $prefix): string
    {
        $label = trim(substr(Str::slug("{$prefix}-{$source->project->slug}"), 0, 63), '-');
        $hostname = strtolower("{$label}.{$source->preview_domain}");
        if (Website::withTrashed()->where('url', $hostname)->exists()) {
            $label = rtrim(substr($label, 0, 62 - strlen((string) $source->id)), '-')."-{$source->id}";
            $hostname = strtolower("{$label}.{$source->preview_domain}");
        }

        return $hostname;
    }

    /**
     * Restore a cleaned-up preview website and set it up again, with a new database password and `.env`.
     *
     * @param  Preview  $preview
     * @param  Website  $website
     * @return void
     */
    private function provision(Preview $preview, Website $website): void
    {
        if ($website->trashed()) {
            $website->restore();
        }
        $website->forceFill([
            'provisioning_status' => Website::STATUS_QUEUED, 'setup_stage' => 0, 'provisioning_token' => (string) Str::uuid(), 'provisioning_error' => null,
            'provisioned_at' => null, 'database_password' => Str::random(32), 'env_file' => null,
        ]);
        $website->env_file = $this->configuration->environmentFile($preview, $website);
        $website->save();
        ProvisionWebsite::dispatch($website->id, (string) $website->provisioning_token)->afterCommit();
    }

    /**
     * Deploy the preview's latest revision through its repository, running the initialisation command until a deploy
     * with it succeeds. Nothing happens while the website isn't ready or already has a deploy running; that deploy
     * finishing calls back here.
     *
     * @param  Preview  $preview
     * @return void
     */
    private function deployLatest(Preview $preview): void
    {
        $preview->refresh();
        $repository = $preview->repository;
        if (! $preview->isOpen() || $repository === null || $repository->trashed() || ! $repository->isDeploymentReady()) {
            return;
        }
        $payload = $this->payload->for($repository);
        $command = trim((string) $preview->sourceRepository->preview_initialization_command);
        if ($preview->initialized_at === null && $command !== '') {
            $payload['preview_initialization'] = ['command' => $command, 'attempt' => 1, 'revision' => $preview->revision];
        }
        $build = $this->deployments->queue($repository, ['trigger_source' => 'preview', 'revision' => $preview->revision !== '' ? $preview->revision : null, 'commit_message' => $preview->title, 'environment_payload' => $payload]);
        if ($build !== null) {
            $preview->forceFill(['status' => Preview::STATUS_DEPLOYING])->save();
        }
    }
}
