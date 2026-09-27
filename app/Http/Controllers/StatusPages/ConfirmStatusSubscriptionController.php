<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\ConfirmStatusSubscription;
use App\Models\StatusSubscription;
use Illuminate\Http\RedirectResponse;

/** The link in the confirmation email. The URL shape is Deployer's, so links already sent keep working after import. */
final class ConfirmStatusSubscriptionController
{
    public function __invoke(string $subscription, string $token, ConfirmStatusSubscription $confirm): RedirectResponse
    {
        $record = StatusSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless($confirm->handle($record, $token), 404);

        return to_route('status.show', $record->statusPage->slug)->with('status', __('You’ll get an email when this page posts an update.'));
    }
}
