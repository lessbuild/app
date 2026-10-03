<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\StatusPage;
use App\Models\User;
use App\Support\Hostname;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveStatusPageDomain
{
    /**
     * Set the hostname a status page should be served on, or clear it with null. A new hostname starts unverified with
     * a fresh TXT value to publish; keeping the same one changes nothing.
     *
     * @param  User  $actor
     * @param  StatusPage  $page
     * @param  string|null  $input  the hostname as typed
     * @return void
     */
    public function handle(User $actor, StatusPage $page, ?string $input): void
    {
        Gate::forUser($actor)->authorize('update', $page);
        if ($input === null || trim($input) === '') {
            $page->forceFill(['custom_domain' => null, 'custom_domain_token' => null, 'custom_domain_verified_at' => null])->save();

            return;
        }
        $hostname = Hostname::normalize($input);
        if ($hostname === null) {
            throw ValidationException::withMessages(['custom_domain' => __('Enter a hostname such as status.example.com.')]);
        }
        $platform = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($platform) && ($hostname === $platform || str_ends_with($hostname, '.'.$platform))) {
            throw ValidationException::withMessages(['custom_domain' => __('Use a domain of your own.')]);
        }
        if ($hostname === $page->custom_domain) {
            return;
        }
        if (StatusPage::query()->where('custom_domain', $hostname)->whereKeyNot($page->id)->exists()) {
            throw ValidationException::withMessages(['custom_domain' => __('Another status page already uses this domain.')]);
        }
        $page->forceFill(['custom_domain' => $hostname, 'custom_domain_token' => Str::random(32), 'custom_domain_verified_at' => null])->save();
    }
}
