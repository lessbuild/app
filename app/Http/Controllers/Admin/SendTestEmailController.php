<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Admin\EmailDelivery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SendTestEmailController
{
    /**
     * Send a test email to an address (the admin's own by default) and show how it went.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  EmailDelivery  $email
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, EmailDelivery $email): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:254']]);
        $result = $email->sendTest($user, (string) $data['to']);

        return to_route('admin.email')->with($result['sent'] ? 'status' : 'error', $result['sent']
            ? __('Sent to :to. If it doesn’t arrive within a few minutes, check the spam folder and the mail provider’s logs.', ['to' => $result['to']])
            : __('Sending failed: :error', ['error' => $result['error']]));
    }
}
