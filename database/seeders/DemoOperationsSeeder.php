<?php

namespace Database\Seeders;

use App\Models\Build;
use App\Models\Event;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AccountSecurityNotification;
use App\Notifications\FailureNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoOperationsSeeder extends Seeder
{
    public function run(): void
    {
        Model::withoutEvents(function (): void {
            DB::transaction(function (): void {
                $user = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();
                $server = $user->servers()->where('name', DemoSeeder::PREFIX.'Production application')->firstOrFail();
                $failedServer = $user->servers()->where('name', DemoSeeder::PREFIX.'Failed worker')->firstOrFail();
                $queuedServer = $user->servers()->where('name', DemoSeeder::PREFIX.'Queued application')->firstOrFail();
                $provisioningServer = $user->servers()->where('name', DemoSeeder::PREFIX.'Provisioning worker')->firstOrFail();
                $healthyWebsite = $user->websites()->where('deployment_slug', 'demo-storefront')->firstOrFail();
                $unhealthyWebsite = $user->websites()->where('deployment_slug', 'demo-status')->firstOrFail();
                $failedWebsite = $user->websites()->where('deployment_slug', 'demo-provisioning-failure')->firstOrFail();
                $github = $user->repositories()->where('name', DemoSeeder::PREFIX.'Storefront repository')->firstOrFail();
                $gitlab = $user->repositories()->where('name', DemoSeeder::PREFIX.'Status repository')->firstOrFail();
                $bitbucket = $user->repositories()->where('name', DemoSeeder::PREFIX.'Worker repository')->firstOrFail();

                $builds = $this->builds($github, $gitlab, $bitbucket);
                $this->webhookDeliveries($github, $gitlab, $bitbucket, $builds);
                $this->logs($server, $failedServer, $queuedServer, $provisioningServer, $healthyWebsite, $failedWebsite, $builds);
                $commands = $this->commands($user, $server);
                $this->activity($user, $server, $failedServer, $healthyWebsite, $unhealthyWebsite, $builds, $commands);
                $this->notifications($user, $failedServer, $unhealthyWebsite, $builds['gitlab_failed']);
            });
        });
    }

    /** @return array<string, Build> */
    private function builds(Repository $github, Repository $gitlab, Repository $bitbucket): array
    {
        $githubBase = $this->build($github, str_repeat('a', 40), [
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'commit_message' => 'Demo initial storefront release',
            'started_at' => now()->subDays(7)->addMinutes(1),
            'last_heartbeat_at' => now()->subDays(7)->addMinutes(4),
            'finished_at' => now()->subDays(7)->addMinutes(5),
            'built_at' => now()->subDays(7)->addMinutes(5),
            'created_at' => now()->subDays(7),
        ]);
        $githubFailed = $this->build($github, str_repeat('b', 40), [
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Demo storefront checkout failure',
            'failure_message' => 'Demo dependency installation failed with exit code 1.',
            'started_at' => now()->subDays(6)->addMinute(),
            'last_heartbeat_at' => now()->subDays(6)->addMinutes(2),
            'finished_at' => now()->subDays(6)->addMinutes(2),
            'created_at' => now()->subDays(6),
        ]);
        $githubRedeploy = $this->build($github, str_repeat('a', 40), [
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_REDEPLOY,
            'commit_message' => 'Demo rollback to stable storefront release',
            'operator_note' => '[Demo] Approved rollback for incident DEMO-1042 after the checkout failure.',
            'redeployed_from_build_id' => $githubBase->id,
            'started_at' => now()->subDays(5)->addMinute(),
            'last_heartbeat_at' => now()->subDays(5)->addMinutes(3),
            'finished_at' => now()->subDays(5)->addMinutes(4),
            'built_at' => now()->subDays(5)->addMinutes(4),
            'created_at' => now()->subDays(5),
        ], 'demo-redeploy');
        $gitlabSuccess = $this->build($gitlab, str_repeat('c', 40), [
            'status' => Build::STATUS_SUCCEEDED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Demo status page release',
            'started_at' => now()->subDays(4)->addMinute(),
            'finished_at' => now()->subDays(4)->addMinutes(3),
            'built_at' => now()->subDays(4)->addMinutes(3),
            'created_at' => now()->subDays(4),
        ]);
        $gitlabRejected = $this->build($gitlab, str_repeat('9', 40), [
            'status' => Build::STATUS_REJECTED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'commit_message' => 'Demo release rejected during approval',
            'approval_note' => 'Demo rejection: maintenance window was not approved.',
            'rejected_at' => now()->subDays(3)->subHours(3),
            'finished_at' => now()->subDays(3)->subHours(3),
            'created_at' => now()->subDays(3)->subHours(4),
        ]);
        $gitlabFailed = $this->build($gitlab, str_repeat('d', 40), [
            'status' => Build::STATUS_FAILED,
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'commit_message' => 'Demo release with failing health check',
            'operator_note' => '[Demo] Follow up with the status-page owner before the next deployment window.',
            'failure_message' => 'Demo post-deployment health check returned HTTP 503; previous release restored.',
            'started_at' => now()->subDays(2)->addMinute(),
            'last_heartbeat_at' => now()->subDays(2)->addMinutes(4),
            'finished_at' => now()->subDays(2)->addMinutes(5),
            'created_at' => now()->subDays(2),
        ]);
        $bitbucketCanceled = $this->build($bitbucket, str_repeat('f', 40), [
            'status' => Build::STATUS_CANCELED,
            'trigger_source' => Build::TRIGGER_MANUAL,
            'commit_message' => 'Demo canceled worker release',
            'failure_message' => 'Deployment canceled before the worker started.',
            'finished_at' => now()->subDay()->addMinute(),
            'created_at' => now()->subDay(),
        ]);

        return [
            'github_base' => $githubBase,
            'github_failed' => $githubFailed,
            'github_redeploy' => $githubRedeploy,
            'gitlab_success' => $gitlabSuccess,
            'gitlab_failed' => $gitlabFailed,
            'gitlab_rejected' => $gitlabRejected,
            'bitbucket_canceled' => $bitbucketCanceled,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function build(Repository $repository, string $revision, array $attributes, ?string $key = null): Build
    {
        $createdAt = $attributes['created_at'];
        unset($attributes['created_at']);
        $identity = [
            'revision' => $revision,
            'trigger_source' => $attributes['trigger_source'],
        ];
        $identity[$key ? 'redeployed_from_build_id' : 'commit_message'] = $key
            ? $attributes['redeployed_from_build_id']
            : $attributes['commit_message'];
        $build = $repository->builds()->updateOrCreate($identity, $attributes);
        $build->forceFill(['created_at' => $createdAt, 'updated_at' => $attributes['finished_at'] ?? $createdAt])->save();

        return $build;
    }

    /** @param array<string, Build> $builds */
    private function webhookDeliveries(
        Repository $github,
        Repository $gitlab,
        Repository $bitbucket,
        array $builds,
    ): void {
        $definitions = [
            [$github, 'demo-github-queued', RepositoryWebhookDelivery::STATUS_QUEUED, str_repeat('a', 40), 'Demo stable push', $builds['github_redeploy']],
            [$github, 'demo-github-superseded', RepositoryWebhookDelivery::STATUS_SUPERSEDED, str_repeat('b', 40), 'Demo older coalesced push', null],
            [$gitlab, 'demo-gitlab-pending', RepositoryWebhookDelivery::STATUS_PENDING, str_repeat('e', 40), 'Demo pending status-page release', null],
            [$gitlab, 'demo-gitlab-unavailable', RepositoryWebhookDelivery::STATUS_UNAVAILABLE, str_repeat('d', 40), 'Demo push while infrastructure was unavailable', null],
            [$bitbucket, 'demo-bitbucket-received', RepositoryWebhookDelivery::STATUS_RECEIVED, str_repeat('f', 40), 'Demo delivery interrupted before classification', null],
        ];

        foreach ($definitions as $index => [$repository, $deliveryId, $status, $revision, $message, $build]) {
            $delivery = $repository->webhookDeliveries()->updateOrCreate(
                ['delivery_id' => $deliveryId],
                [
                    'status' => $status,
                    'revision' => $revision,
                    'commit_message' => $message,
                    'build_id' => $build?->id,
                ],
            );
            $delivery->forceFill([
                'created_at' => now()->subHours(6 - $index),
                'updated_at' => now()->subHours(6 - $index),
            ])->save();
        }
    }

    /** @param array<string, Build> $builds */
    private function logs(
        Server $server,
        Server $failedServer,
        Server $queuedServer,
        Server $provisioningServer,
        Website $healthyWebsite,
        Website $failedWebsite,
        array $builds,
    ): void {
        foreach ([
            'apt' => "Start-Date: Demo package update\nUpgrade: curl, openssl\nEnd-Date: Demo package update complete",
            'caddy' => "demo-storefront.example.com: configuration loaded\nTLS certificate maintenance complete",
            'mysql' => '[Note] Demo MySQL server ready for connections',
            'php' => 'NOTICE: demo PHP-FPM worker pool is ready',
            'provisioning' => 'Demo provisioning completed successfully',
        ] as $type => $log) {
            $server->logSnapshots()->updateOrCreate(
                ['type' => $type],
                [
                    'status' => ServerLogSnapshot::STATUS_READY,
                    'log' => $log,
                    'error' => null,
                    'refreshed_at' => now()->subMinutes(10),
                ],
            );
        }
        $failedServer->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_FAILED,
                'log' => "Installing Node.js\nDemo package mirror returned HTTP 503",
                'error' => 'Demo remote provisioning command exited with status 1.',
                'refreshed_at' => now()->subDays(3),
            ],
        );
        $queuedServer->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_QUEUED,
                'log' => null,
                'error' => null,
                'refreshed_at' => null,
            ],
        );
        $provisioningServer->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_REFRESHING,
                'log' => "Installing system packages\nDemo provisioning is still running",
                'error' => null,
                'refreshed_at' => now()->subMinute(),
            ],
        );
        $healthyWebsite->logs()->updateOrCreate(
            ['type' => Website::PROVISIONING_LOG_TYPE],
            ['log' => "Configured Caddy\nCreated demo database\nPublished environment\nWebsite ready"],
        );
        $failedWebsite->logs()->updateOrCreate(
            ['type' => Website::PROVISIONING_LOG_TYPE],
            ['log' => "Configured demo virtual host\nCaddy validation failed: duplicate host matcher"],
        );
        foreach ($builds as $key => $build) {
            $result = match ($build->status) {
                Build::STATUS_SUCCEEDED => 'Deployment completed successfully.',
                Build::STATUS_FAILED => 'Deployment failed and the previous release was restored.',
                default => 'Deployment canceled before execution.',
            };
            $build->logs()->updateOrCreate(
                ['type' => Build::DEPLOYMENT_LOG_TYPE],
                ['log' => "[demo] Fetching revision {$build->shortRevision()}\n[demo] {$key}\n{$result}"],
            );
        }
    }

    /** @return array<string, ServerCommandExecution> */
    private function commands(User $user, Server $server): array
    {
        $definitions = [
            'running' => ['php artisan queue:work --once', ServerCommandExecution::STATUS_RUNNING, 'Demo worker is processing one queued job.', null],
            'succeeded' => ['php -v', ServerCommandExecution::STATUS_SUCCEEDED, "PHP 8.3.24 (cli)\nDemo command completed.", 0],
            'failed' => ['php artisan demo:missing', ServerCommandExecution::STATUS_FAILED, 'ERROR: Command "demo:missing" is not defined.', 1],
            'canceled' => ['composer audit', ServerCommandExecution::STATUS_CANCELED, null, null],
            'queued' => ['uptime', ServerCommandExecution::STATUS_QUEUED, null, null],
        ];
        $commands = [];

        foreach (array_values($definitions) as $index => $definition) {
            [$command, $status, $output, $exitCode] = $definition;
            $execution = $server->commandExecutions->first(fn (ServerCommandExecution $candidate): bool => $candidate->command === $command)
                ?? new ServerCommandExecution(['server_id' => $server->id, 'user_id' => $user->id]);
            $terminal = in_array($status, ServerCommandExecution::TERMINAL_STATUSES, true);
            $execution->forceFill([
                'server_id' => $server->id,
                'user_id' => $user->id,
                'command' => $command,
                'status' => $status,
                'output' => $output,
                'exit_code' => $exitCode,
                'started_at' => $status === ServerCommandExecution::STATUS_QUEUED ? null : now()->subHours(5 - $index),
                'finished_at' => $terminal ? now()->subHours(5 - $index)->addMinute() : null,
                'created_at' => now()->subHours(6 - $index),
                'updated_at' => now()->subHours(5 - $index),
            ])->save();
            $commands[$status] = $execution;
        }

        $source = $commands[ServerCommandExecution::STATUS_SUCCEEDED];
        $rerun = $server->commandExecutions()
            ->where('rerun_from_execution_id', $source->id)
            ->first() ?? new ServerCommandExecution;
        $rerun->forceFill([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'command' => $source->command,
            'status' => ServerCommandExecution::STATUS_SUCCEEDED,
            'rerun_from_execution_id' => $source->id,
            'output' => "PHP 8.3.24 (cli)\nDemo command rerun completed.",
            'exit_code' => 0,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinute(),
            'created_at' => now()->subHour()->subMinute(),
            'updated_at' => now()->subHour()->addMinute(),
        ])->save();
        $commands['rerun'] = $rerun;

        return $commands;
    }

    /**
     * @param  array<string, Build>  $builds
     * @param  array<string, ServerCommandExecution>  $commands
     */
    private function activity(
        User $user,
        Server $server,
        Server $failedServer,
        Website $healthyWebsite,
        Website $unhealthyWebsite,
        array $builds,
        array $commands,
    ): void {
        $definitions = [
            [$builds['github_redeploy'], 'deployment', 'Demo: storefront rollback deployment succeeded.'],
            [$healthyWebsite, 'website', 'Demo: storefront health check recovered.'],
            [$failedServer, 'server', 'Demo: worker server provisioning failed.'],
            [$commands[ServerCommandExecution::STATUS_SUCCEEDED], 'command', 'Demo: server command succeeded.'],
            [$user->providers()->where('name', DemoSeeder::PREFIX.'GitLab')->firstOrFail(), 'provider', 'Demo: GitLab credential check failed.'],
            [$user->recipes()->where('name', DemoSeeder::PREFIX.'Imported SSH hardening')->firstOrFail(), 'recipe', 'Demo: gallery recipe update is ready to review.'],
            [$server, 'general', 'Demo: fixture data was refreshed.'],
        ];

        Event::query()->where('user_id', $user->id)->where('event', 'like', 'Demo:%')->delete();
        foreach ($definitions as $index => [$subject, $category, $message]) {
            $event = $subject->events()->create([
                'user_id' => $user->id,
                'category' => $category,
                'event' => $message,
            ]);
            $event->forceFill([
                'created_at' => now()->subHours(count($definitions) - $index),
                'updated_at' => now()->subHours(count($definitions) - $index),
            ])->save();
        }

        $accountEvent = $user->accountEvents()->create([
            'user_id' => $user->id,
            'category' => 'account',
            'event' => 'Demo: account security settings were reviewed.',
        ]);
        $accountEvent->forceFill([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ])->save();
    }

    private function notifications(User $user, Server $server, Website $website, Build $build): void
    {
        $provider = $user->providers()->where('name', DemoSeeder::PREFIX.'GitLab')->firstOrFail();
        $definitions = [
            'd0000000-0000-4000-8000-000000000001' => ['server', $server->id, 'Demo server provisioning failed', 'The demo worker stopped while installing Node.js.', 'failed', null],
            'd0000000-0000-4000-8000-000000000002' => ['website', $website->id, 'Demo website is unhealthy', 'The demo health endpoint returned HTTP 503.', 'failed', null],
            'd0000000-0000-4000-8000-000000000003' => ['deployment', $build->id, 'Demo deployment failed', 'The demo health check failed and restored the previous release.', 'failed', now()->subDay()],
            'd0000000-0000-4000-8000-000000000004' => ['provider', $provider->id, 'Demo provider connection failed', 'The demo GitLab credential was rejected.', 'failed', now()->subHours(12)],
            'd0000000-0000-4000-8000-000000000005' => ['provider', $provider->id, 'Demo provider connection recovered', 'The demo provider credential was restored.', 'healthy', null],
            'd0000000-0000-4000-8000-000000000006' => ['account', $user->id, 'Demo account security changed', 'Demo: another browser session was logged out.', 'info', null],
        ];

        foreach ($definitions as $position => $definition) {
            [$category, $resourceId, $title, $message, $status, $readAt] = $definition;
            $notification = $user->notifications()->updateOrCreate(
                ['id' => $position],
                [
                    'type' => $category === 'account'
                        ? AccountSecurityNotification::class
                        : FailureNotification::class,
                    'data' => compact('category', 'title', 'message', 'status') + [
                        'resource_id' => $resourceId,
                        'demo' => true,
                    ],
                    'read_at' => $readAt,
                ],
            );
            $notification->forceFill([
                'created_at' => now()->subHours(count($definitions) - array_search($position, array_keys($definitions), true)),
                'updated_at' => now(),
            ])->save();
        }
    }
}
