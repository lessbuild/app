<?php

declare(strict_types=1);

namespace App\Actions\Agency;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveBranding
{
    /**
     * Set the name, logo and colour clients see on status pages, shared reports and client reports instead of ours.
     * An empty name turns white labelling off.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  array{brand_name?: string|null, brand_logo_url?: string|null, brand_color?: string|null}  $data
     * @return void
     */
    public function handle(User $actor, Account $account, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        $name = trim((string) ($data['brand_name'] ?? ''));
        $logo = trim((string) ($data['brand_logo_url'] ?? ''));
        $color = strtolower(trim((string) ($data['brand_color'] ?? '')));
        if ($logo !== '' && (! str_starts_with($logo, 'https://') || filter_var($logo, FILTER_VALIDATE_URL) === false)) {
            throw ValidationException::withMessages(['brand_logo_url' => __('Use an HTTPS address for the logo.')]);
        }
        if ($color !== '' && preg_match('/\A#[0-9a-f]{6}\z/', $color) !== 1) {
            throw ValidationException::withMessages(['brand_color' => __('Use a colour like #1f6feb.')]);
        }
        $account->forceFill([
            'brand_name' => $name !== '' ? mb_substr($name, 0, 100) : null,
            'brand_logo_url' => $logo !== '' ? $logo : null,
            'brand_color' => $color !== '' ? $color : null,
        ])->save();
    }
}
