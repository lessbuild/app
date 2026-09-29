<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Audit\SaveAuditStream;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AuditStream;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreAuditStreamController
{
    /**
     * Add an audit stream and return to the audit log, showing a webhook's signing secret once.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveAuditStream  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveAuditStream $save): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(AuditStream::TYPES))],
            'endpoint_url' => ['nullable', 'string', 'max:2048'],
            'backup_destination_id' => ['nullable', 'integer'],
        ]);
        [, $secret] = $save->handle($user, $account, [
            'name' => (string) $data['name'], 'type' => (string) $data['type'],
            'endpoint_url' => $data['endpoint_url'] ?? null, 'backup_destination_id' => isset($data['backup_destination_id']) ? (int) $data['backup_destination_id'] : null,
        ]);

        return to_route('account.audit-log')->with('status', __('Audit stream added. New entries are sent to it as they happen.'))->with('stream_secret', $secret);
    }
}
