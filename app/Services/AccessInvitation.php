<?php

namespace App\Services;

use App\Models\AccessRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessInvitation
{
    public function issue(AccessRequest $request): string
    {
        $token = Str::random(64);
        $request->update([
            'status' => 'invited',
            'invitation_token_hash' => hash('sha256', $token),
            'invited_at' => now(),
            'invitation_expires_at' => now()->addDays((int) config('lessbuild.registration.invitation_days', 7)),
            'accepted_at' => null,
        ]);

        return $token;
    }

    public function find(string $token): ?AccessRequest
    {
        if (strlen($token) !== 64) {
            return null;
        }

        $request = AccessRequest::query()->where('invitation_token_hash', hash('sha256', $token))->first();

        return $request?->invitationIsValid() ? $request : null;
    }

    public function consume(string $token, callable $callback): mixed
    {
        return DB::transaction(function () use ($token, $callback): mixed {
            $request = AccessRequest::query()->where('invitation_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $request?->invitationIsValid()) {
                return null;
            }

            $result = $callback($request);
            $request->update(['status' => 'accepted', 'accepted_at' => now(), 'invitation_token_hash' => null]);

            return $result;
        });
    }
}
