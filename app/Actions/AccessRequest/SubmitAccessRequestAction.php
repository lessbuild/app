<?php

namespace App\Actions\AccessRequest;

use App\Models\AccessRequest;
use App\Notifications\AccessRequestReceivedNotification;
use App\Notifications\NewAccessRequestNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;

class SubmitAccessRequestAction
{
    public function __construct(private readonly Dispatcher $notifications) {}

    /**
     * Create or update a pending access request and notify only for a newly created applicant record.
     *
     * @param  array{name: string, email: string, company: ?string, team_size: ?string, plan: ?string, use_case: string}  $attributes  Validated and normalized applicant fields.
     */
    public function handle(array $attributes): void
    {
        $emailHash = hash('sha256', $attributes['email']);
        $existing = AccessRequest::query()->where('email_hash', $emailHash)->first();
        $requestAttributes = [
            'email_hash' => $emailHash,
            'email' => $attributes['email'],
            'name' => $attributes['name'],
            'company' => $attributes['company'],
            'team_size' => $attributes['team_size'],
            'plan' => $attributes['plan'],
            'use_case' => $attributes['use_case'],
        ];

        $created = false;
        if ($existing) {
            // Preserve an operator's decision while allowing pending leads to add context.
            if ($existing->status === 'pending') {
                $existing->update($requestAttributes);
            }
        } else {
            AccessRequest::query()->create($requestAttributes);
            $created = true;
        }

        if (! $created) {
            return;
        }

        $this->notifications->send(
            (new AnonymousNotifiable)->route('mail', $attributes['email']),
            new AccessRequestReceivedNotification,
        );
        foreach (config('lessbuild.platform_admin_emails', []) as $adminEmail) {
            $this->notifications->send(
                (new AnonymousNotifiable)->route('mail', $adminEmail),
                new NewAccessRequestNotification,
            );
        }
    }
}
