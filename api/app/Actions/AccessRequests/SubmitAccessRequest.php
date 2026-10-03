<?php

declare(strict_types=1);

namespace App\Actions\AccessRequests;

use App\Models\AccessRequest;
use App\Models\User;
use App\Notifications\AccessRequestReceived;
use App\Notifications\NewAccessRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

final class SubmitAccessRequest
{
    /**
     * Record a request for access, or add detail to an earlier one still waiting. A new request gets a receipt email and
     * tells the platform admins; a repeat changes nothing an admin has already decided and sends nothing.
     *
     * @param  array{name: string, email: string, company: string|null, team_size: string|null, use_case: string}  $data
     * @return void
     */
    public function handle(array $data): void
    {
        $hash = AccessRequest::hashEmail($data['email']);
        $existing = AccessRequest::query()->where('email_hash', $hash)->first();
        if ($existing !== null) {
            if ($existing->status === 'pending') {
                $existing->forceFill([...$data, 'email' => mb_strtolower(trim($data['email']))])->save();
            }

            return;
        }
        $request = new AccessRequest;
        $request->forceFill([...$data, 'email' => mb_strtolower(trim($data['email'])), 'email_hash' => $hash, 'status' => 'pending'])->save();
        (new AnonymousNotifiable)->route('mail', $request->email)->notify(new AccessRequestReceived);
        Notification::send(User::query()->where('is_platform_admin', true)->get(), new NewAccessRequest($request));
    }
}
