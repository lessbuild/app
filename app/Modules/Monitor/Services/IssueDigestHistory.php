<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\IssueDigestDelivery;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Collection;

final readonly class IssueDigestHistory
{
    public function __construct(private IssueDigestSourceAccess $access) {}

    /** @return Collection<int, IssueDigestDelivery> */
    public function forRecipient(Workspace $workspace, User $recipient): Collection
    {
        if ($workspace->roleFor($recipient) === null) {
            return collect();
        }
        $deliveries = $workspace->issueDigestDeliveries()->forRecipient($recipient)
            ->latest('period_end')->latest('id')->limit(20)->get();
        $allowed = $this->access->allowedScopes($workspace, $recipient, $deliveries->pluck('source_scope', 'id')->all());

        return $deliveries->map(function (IssueDigestDelivery $delivery) use ($allowed): IssueDigestDelivery {
            $visible = $allowed[$delivery->id] ?? false;
            $delivery->setAttribute('summary_available', $visible);
            if (! $visible) {
                foreach (['new_count', 'resolved_count', 'open_count', 'critical_open_count'] as $attribute) {
                    $delivery->setAttribute($attribute, null);
                }
            }
            $delivery->offsetUnset('source_scope');

            return $delivery;
        });
    }
}
