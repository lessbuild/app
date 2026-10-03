<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

/** The events webhook endpoints can receive, grouped for the form, with what each means. */
final class WebhookEvents
{
    /**
     * Every event by group: name => description.
     *
     * @var array<string, array<string, string>>
     */
    public const GROUPS = [
        'Deploy' => [
            'deploy.started' => 'A deploy started.',
            'deploy.succeeded' => 'A deploy went live.',
            'deploy.failed' => 'A deploy failed, was rejected or was cancelled.',
            'deploy.awaiting_approval' => 'A deploy is waiting for approval.',
        ],
        'Monitoring' => [
            'incident.opened' => 'An incident opened.',
            'incident.resolved' => 'An incident was resolved.',
        ],
        'Infrastructure' => [
            'server.created' => 'A server was created or imported.',
            'server.ready' => 'A server finished provisioning.',
            'server.failed' => 'A server failed to provision.',
            'server.deleted' => 'A server was deleted.',
            'website.ready' => 'A website finished setting up.',
            'website.failed' => 'A website failed to set up.',
            'backup.succeeded' => 'A backup finished.',
            'backup.failed' => 'A backup failed.',
        ],
        'Security' => [
            'security.finding' => 'Security found a new problem.',
        ],
        'Account' => [
            'project.created' => 'A project was created.',
            'project.deleted' => 'A project was deleted.',
            'environment.created' => 'An environment was created.',
            'domain.verified' => 'A domain was verified.',
            'member.joined' => 'Someone accepted an invitation.',
            'member.removed' => 'A member was removed.',
            'billing.plan_changed' => 'A service’s plan changed.',
        ],
    ];

    /**
     * Get every event name.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_merge(...array_map(array_keys(...), array_values(self::GROUPS)));
    }
}
