<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Enums\AlertDestinationType;
use App\Models\Account;
use App\Models\AuditStream;
use App\Models\BackupDestination;
use App\Models\User;
use App\Services\Monitoring\PublicWebhookTarget;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveAuditStream
{
    /**
     * Create a new SaveAuditStream instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks Slack and webhook addresses.
     */
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /**
     * Add a stream that receives the account's audit entries as they happen. Webhooks get a signing secret, returned
     * once.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  array{name: string, type: string, endpoint_url?: string|null, backup_destination_id?: int|null}  $data
     * @return array{0: AuditStream, 1: string|null}
     */
    public function handle(User $actor, Account $account, array $data): array
    {
        Gate::forUser($actor)->authorize('update', $account);
        $type = $data['type'];
        $stream = new AuditStream;
        $secret = null;
        if ($type === 's3') {
            $destination = BackupDestination::query()->where('account_id', $account->id)->find($data['backup_destination_id'] ?? 0)
                ?? throw ValidationException::withMessages(['backup_destination_id' => __('Choose one of this account’s backup destinations.')]);
            $stream->forceFill(['backup_destination_id' => $destination->id]);
        } else {
            $url = trim((string) ($data['endpoint_url'] ?? ''));
            if ($this->targets->host($url, $type === 'slack' ? AlertDestinationType::Slack : AlertDestinationType::Webhook) === null) {
                throw ValidationException::withMessages(['endpoint_url' => $type === 'slack' ? __('Paste a Slack incoming webhook address.') : __('Use a public HTTPS address on port 443.')]);
            }
            $secret = $type === 'webhook' ? Str::random(48) : null;
            $stream->forceFill(['endpoint_url' => $url, 'signing_secret' => $secret]);
        }
        $stream->forceFill(['account_id' => $account->id, 'name' => trim($data['name']), 'type' => $type, 'enabled' => true])->save();

        return [$stream, $secret];
    }
}
