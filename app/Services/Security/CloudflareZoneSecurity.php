<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\SecurityZone;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Applies a zone's security level, bot fight mode and "under attack" mode through Cloudflare's API. */
final class CloudflareZoneSecurity
{
    /**
     * Push the zone's settings to Cloudflare and remember its name. "Under attack" overrides the security level while
     * it's on.
     *
     * @param  SecurityZone  $zone
     * @return void
     */
    public function apply(SecurityZone $zone): void
    {
        $token = (string) $zone->provider->token;
        $client = $this->client($token);
        $name = $client->get("/zones/{$zone->zone_id}")->json('result.name');
        $level = $client->patch("/zones/{$zone->zone_id}/settings/security_level", ['value' => $zone->under_attack ? 'under_attack' : $zone->security_level]);
        $this->check($level->json('success'), $level->json('errors.0.message'), 'security level');
        $bots = $client->put("/zones/{$zone->zone_id}/bot_management", ['fight_mode' => $zone->bot_fight_mode]);
        $this->check($bots->json('success'), $bots->json('errors.0.message'), 'bot fight mode');
        $zone->forceFill(['zone_name' => is_string($name) ? $name : $zone->zone_name, 'applied_at' => now(), 'last_error' => null])->save();
    }

    /**
     * Throw with Cloudflare's reason when a change was refused.
     *
     * @param  mixed  $success
     * @param  mixed  $message
     * @param  string  $what
     * @return void
     */
    private function check(mixed $success, mixed $message, string $what): void
    {
        if ($success !== true) {
            throw new RuntimeException("Cloudflare refused the {$what}: ".(is_string($message) ? $message : 'unknown error').'.');
        }
    }

    /**
     * Build an HTTP client for the Cloudflare API.
     *
     * @param  string  $token
     * @return PendingRequest
     */
    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('infrastructure.cloudflare_api_url'), '/'))->acceptJson()->asJson()->withToken($token)
            ->connectTimeout(5)->timeout(15);
    }
}
