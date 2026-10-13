<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Build;
use App\Models\Website;
use Dotenv\Dotenv;
use Throwable;

/**
 * Works out which MySQL database a website's app really uses, from the settings its last deploy wrote to `.env`, so
 * the Database tab can say when that isn't the website's own (an API sharing its website's database, say).
 */
final class WebsiteDatabaseInUse
{
    /**
     * Find the database on the website's server that its settings point at, when it isn't the website's own: its name
     * and the website it belongs to, if one on the same server does. Null when the settings use the website's own
     * database, another kind of database, or one on another host.
     *
     * @param  Website  $website
     * @return array{name: string, website: Website|null}|null
     */
    public function elsewhere(Website $website): ?array
    {
        $settings = $this->settings($website);
        $connection = strtolower(trim((string) ($settings['DB_CONNECTION'] ?? '')));
        $host = strtolower(trim((string) ($settings['DB_HOST'] ?? '')));
        $name = trim((string) ($settings['DB_DATABASE'] ?? ''));
        if (! in_array($connection, ['', 'mysql', 'mariadb'], true) || ! in_array($host, ['', '127.0.0.1', 'localhost'], true)
            || $name === '' || $name === $website->databaseIdentifier()) {
            return null;
        }
        $owner = Website::query()->where('server_id', $website->server_id)->whereKeyNot($website->id)
            ->whereIn('deployment_slug', array_unique([$name, str_replace('_', '-', $name)]))->get()
            ->first(fn (Website $candidate): bool => $candidate->databaseIdentifier() === $name);

        return ['name' => $name, 'website' => $owner];
    }

    /**
     * Read the settings the website's last deploy wrote (a successful one when there is one): its base `.env`, then
     * the environment's variables and resources on top, as the deploy merges them.
     *
     * @param  Website  $website
     * @return array<string, mixed>
     */
    private function settings(Website $website): array
    {
        $build = Build::query()->where('website_id', $website->id)->where('status', Build::STATUS_SUCCEEDED)->latest('id')->first()
            ?? Build::query()->where('website_id', $website->id)->latest('id')->first();
        $payload = is_array($build?->environment_payload) ? $build->environment_payload : [];
        $base = array_key_exists('base_environment', $payload) ? (string) $payload['base_environment'] : (string) $website->env_file;
        try {
            $settings = Dotenv::parse($base);
        } catch (Throwable) {
            $settings = [];
        }
        foreach (is_array($payload['variables'] ?? null) ? $payload['variables'] : [] as $key => $value) {
            $settings[(string) $key] = $value;
        }
        foreach (is_array($payload['resources'] ?? null) ? $payload['resources'] : [] as $resource) {
            foreach (is_array($resource['configuration']['variables'] ?? null) ? $resource['configuration']['variables'] : [] as $key => $value) {
                $settings[(string) $key] = $value;
            }
        }

        return $settings;
    }
}
