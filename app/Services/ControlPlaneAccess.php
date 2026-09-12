<?php

namespace App\Services;

use App\Http\Middleware\EnforceOrganizationSecurity;
use App\Models\User;
use Illuminate\Http\Request;

class ControlPlaneAccess
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly EnforceOrganizationSecurity $security,
    ) {}

    /**
     * Enforce API entitlement, workspace network policy, and a token ability before request validation.
     *
     * @param  Request  $request  Authenticated control-plane request.
     * @param  string  $ability  Required Sanctum ability, such as read, deploy, or manage.
     */
    public function enforce(Request $request, string $ability): void
    {
        /** @var User $user */
        $user = $request->user();
        $this->entitlements->enforce($user, 'api');
        $ranges = $user->currentOrganization?->allowed_ip_ranges ?? [];
        abort_if($ranges !== [] && ! collect($ranges)->contains(fn (string $range): bool => $this->security->contains($range, (string) $request->ip())), 403, 'This network is not allowed by the workspace security policy.');
        abort_unless($user->tokenCan($ability), 403, "Token lacks the {$ability} ability.");
    }
}
