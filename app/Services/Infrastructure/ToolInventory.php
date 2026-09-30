<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads what's set up in Laravel Forge or Ploi through their APIs (read only): servers, their sites with repository,
 * branch, PHP version, environment file and deploy script, and the servers' cron jobs and daemons.
 *
 * @phpstan-import-type SourceServer from \App\Models\ToolMove
 * @phpstan-import-type Site from \App\Models\ToolMove
 */
final class ToolInventory
{
    /**
     * The most servers read, so a huge account doesn't take minutes.
     *
     * @var int
     */
    public const MAX_SERVERS = 30;

    /**
     * Forge's schedule names and the cron expressions they stand for.
     *
     * @var array<string, string>
     */
    private const FORGE_FREQUENCIES = ['minutely' => '* * * * *', 'hourly' => '0 * * * *', 'nightly' => '0 0 * * *', 'weekly' => '0 0 * * 0', 'monthly' => '0 0 1 * *', 'reboot' => '@reboot'];

    /**
     * Read everything from the tool.
     *
     * @param  string  $source  forge or ploi
     * @param  string  $token
     * @return list<SourceServer>
     *
     * @throws RuntimeException when the tool refuses the token or can't be reached
     */
    public function read(string $source, string $token): array
    {
        return $source === 'forge' ? $this->forge($token) : $this->ploi($token);
    }

    /**
     * Read Laravel Forge (API v1). Sites live in /home/forge/{domain}.
     *
     * @param  string  $token
     * @return list<SourceServer>
     */
    private function forge(string $token): array
    {
        $client = Http::baseUrl('https://forge.laravel.com/api/v1')->withToken($token)->acceptJson()->timeout(20);
        $servers = [];
        foreach (array_slice($this->list($this->get($client, '/servers', 'Forge'), 'servers'), 0, self::MAX_SERVERS) as $server) {
            $id = (string) ($server['id'] ?? '');
            $sites = [];
            foreach ($this->list($this->get($client, "/servers/{$id}/sites", 'Forge'), 'sites') as $site) {
                $domain = (string) ($site['name'] ?? '');
                $siteId = (string) ($site['id'] ?? '');
                $sites[] = $this->site("{$id}-{$siteId}", $domain, "/home/forge/{$domain}", (string) ($site['directory'] ?? '/public'),
                    $site['repository'] ?? null, $site['repository_branch'] ?? null, $site['php_version'] ?? null,
                    $this->text($client, "/servers/{$id}/sites/{$siteId}/env"), $this->text($client, "/servers/{$id}/sites/{$siteId}/deployment/script"));
            }
            $crons = [];
            foreach ($this->list($this->optional($client, "/servers/{$id}/jobs"), 'jobs') as $job) {
                $frequency = (string) ($job['cron'] ?? self::FORGE_FREQUENCIES[$job['frequency'] ?? ''] ?? '');
                $crons[] = ['command' => (string) ($job['command'] ?? ''), 'user' => (string) ($job['user'] ?? 'forge'), 'frequency' => $frequency];
            }
            $daemons = [];
            foreach ($this->list($this->optional($client, "/servers/{$id}/daemons"), 'daemons') as $daemon) {
                $daemons[] = ['command' => (string) ($daemon['command'] ?? ''), 'user' => (string) ($daemon['user'] ?? 'forge'), 'directory' => is_string($daemon['directory'] ?? null) ? $daemon['directory'] : null, 'processes' => max(1, (int) ($daemon['processes'] ?? 1))];
            }
            $servers[] = ['id' => $id, 'name' => (string) ($server['name'] ?? $id), 'ip' => is_string($server['ip_address'] ?? null) ? $server['ip_address'] : null, 'sites' => $sites, 'crons' => $crons, 'daemons' => $daemons];
        }

        return $servers;
    }

    /**
     * Read Ploi. Sites live in /home/ploi/{domain}.
     *
     * @param  string  $token
     * @return list<SourceServer>
     */
    private function ploi(string $token): array
    {
        $client = Http::baseUrl('https://ploi.io/api')->withToken($token)->acceptJson()->timeout(20);
        $servers = [];
        foreach (array_slice($this->list($this->get($client, '/servers', 'Ploi'), 'data'), 0, self::MAX_SERVERS) as $server) {
            $id = (string) ($server['id'] ?? '');
            $sites = [];
            foreach ($this->list($this->get($client, "/servers/{$id}/sites", 'Ploi'), 'data') as $site) {
                $domain = (string) ($site['domain'] ?? '');
                $siteId = (string) ($site['id'] ?? '');
                $repository = $this->optional($client, "/servers/{$id}/sites/{$siteId}/repository")?->json('data');
                $repository = is_array($repository) ? $repository : [];
                $env = $this->optional($client, "/servers/{$id}/sites/{$siteId}/env")?->json('data');
                $script = $this->optional($client, "/servers/{$id}/sites/{$siteId}/deploy/script")?->json('deploy_script');
                $sites[] = $this->site("{$id}-{$siteId}", $domain, "/home/ploi/{$domain}", (string) ($site['web_directory'] ?? '/public'),
                    $repository['repository'] ?? $repository['name'] ?? null, $repository['branch'] ?? null, $site['php_version'] ?? null,
                    is_string($env) ? $env : '', is_string($script) ? $script : '');
            }
            $crons = [];
            foreach ($this->list($this->optional($client, "/servers/{$id}/crontabs"), 'data') as $cron) {
                $crons[] = ['command' => (string) ($cron['command'] ?? ''), 'user' => (string) ($cron['user'] ?? 'ploi'), 'frequency' => (string) ($cron['frequency'] ?? '')];
            }
            $daemons = [];
            foreach ($this->list($this->optional($client, "/servers/{$id}/daemons"), 'data') as $daemon) {
                $daemons[] = ['command' => (string) ($daemon['command'] ?? ''), 'user' => (string) ($daemon['system_user'] ?? 'ploi'), 'directory' => is_string($daemon['directory'] ?? null) ? $daemon['directory'] : null, 'processes' => max(1, (int) ($daemon['processes'] ?? 1))];
            }
            $servers[] = ['id' => $id, 'name' => (string) ($server['name'] ?? $id), 'ip' => is_string($server['ip_address'] ?? null) ? $server['ip_address'] : null, 'sites' => $sites, 'crons' => $crons, 'daemons' => $daemons];
        }

        return $servers;
    }

    /**
     * Build one site's record.
     *
     * @param  string  $key
     * @param  string  $domain
     * @param  string  $root
     * @param  string  $webDirectory
     * @param  mixed  $repository
     * @param  mixed  $branch
     * @param  mixed  $php
     * @param  string  $env
     * @param  string  $deployScript
     * @return Site
     */
    private function site(string $key, string $domain, string $root, string $webDirectory, mixed $repository, mixed $branch, mixed $php, string $env, string $deployScript): array
    {
        return [
            'key' => $key, 'domain' => $domain, 'root' => $root, 'web_directory' => $webDirectory,
            'repository' => is_string($repository) && $repository !== '' ? $repository : null,
            'branch' => is_string($branch) && $branch !== '' ? $branch : null,
            'php' => is_string($php) && $php !== '' ? $php : null,
            'env' => mb_substr($env, 0, 65535), 'deploy_script' => mb_substr($deployScript, 0, 10000),
        ];
    }

    /**
     * Fetch a page the move can't do without, explaining a refused token.
     *
     * @param  PendingRequest  $client
     * @param  string  $path
     * @param  string  $name
     * @return Response
     *
     * @throws RuntimeException
     */
    private function get(PendingRequest $client, string $path, string $name): Response
    {
        $response = $client->get($path);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(__(':tool refused the API token. Create a new one with read access and try again.', ['tool' => $name]));
        }
        if ($response->failed()) {
            throw new RuntimeException(__(':tool answered HTTP :status.', ['tool' => $name, 'status' => $response->status()]));
        }

        return $response;
    }

    /**
     * Fetch a page the move can do without (a token scope may not cover it), or null.
     *
     * @param  PendingRequest  $client
     * @param  string  $path
     * @return Response|null
     */
    private function optional(PendingRequest $client, string $path): ?Response
    {
        $response = $client->get($path);

        return $response->successful() ? $response : null;
    }

    /**
     * Fetch a plain-text page (Forge's environment file and deploy script), or an empty string.
     *
     * @param  PendingRequest  $client
     * @param  string  $path
     * @return string
     */
    private function text(PendingRequest $client, string $path): string
    {
        $response = (clone $client)->accept('text/plain')->get($path);

        return $response->successful() ? $response->body() : '';
    }

    /**
     * Get a response's list of records under a key.
     *
     * @param  Response|null  $response
     * @param  string  $key
     * @return list<array<string, mixed>>
     */
    private function list(?Response $response, string $key): array
    {
        $items = $response?->json($key);

        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }
}
