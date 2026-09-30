<?php

declare(strict_types=1);

namespace BuildPusher\Sdk;

use Closure;

/**
 * A small client for the BuildPusher API (v1). Create an API token with the scopes you need under Account → API
 * tokens, then:
 *
 *     $client = new Client('bp_…');
 *     $deploy = $client->deploy($environmentId, 'v1.4.0');
 *     $done = $client->waitForDeployment($deploy['id']);
 */
final class Client
{
    /** Deploy statuses that are final. */
    public const FINISHED = ['succeeded', 'failed', 'canceled', 'rejected'];

    /**
     * How requests are sent: fn(string $method, string $url, array $headers, ?string $body): array{status: int, body: string}.
     *
     * @var Closure(string, string, array<string, string>, ?string): array{status: int, body: string}
     */
    private Closure $transport;

    /**
     * Create a new Client instance.
     *
     * @param  string  $token  An API token.
     * @param  string  $baseUrl  The BuildPusher address.
     * @param  (Closure(string, string, array<string, string>, ?string): array{status: int, body: string})|null  $transport  Replaces the HTTP stack, for tests.
     */
    public function __construct(private readonly string $token, private readonly string $baseUrl = 'https://buildpusher.com', ?Closure $transport = null)
    {
        $this->transport = $transport ?? self::streamTransport(...);
    }

    /**
     * Get who the token belongs to and its account.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->data('GET', '/me');
    }

    /**
     * List the projects the token can deploy, with their environments.
     *
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        return array_values($this->data('GET', '/projects'));
    }

    /**
     * Get one project with its environments.
     *
     * @param  string  $projectId
     * @return array<string, mixed>
     */
    public function project(string $projectId): array
    {
        return $this->data('GET', '/projects/'.rawurlencode($projectId));
    }

    /**
     * List the Analytics sites the token can read (needs the analytics:read scope).
     *
     * @return list<array<string, mixed>>
     */
    public function analyticsSites(): array
    {
        return array_values($this->data('GET', '/analytics/sites'));
    }

    /**
     * Get a site's Analytics report as numbers.
     *
     * @param  int  $siteId
     * @param  int  $days  1 (today, per hour), 7, 30, 90 or 365
     * @param  array<string, string>  $filters  path, source, campaign, device or country
     * @return array<string, mixed>
     */
    public function analyticsReport(int $siteId, int $days = 30, array $filters = []): array
    {
        return $this->data('GET', '/analytics/sites/'.$siteId.'/report?'.http_build_query(['days' => $days, ...$filters]));
    }

    /**
     * Send pageviews and custom events from your server (needs the analytics:write scope). Pass each visitor's IP
     * address and User-Agent so they're counted like browser visits; both are discarded after hashing.
     *
     * @param  int  $siteId
     * @param  list<array<string, mixed>>  $events  each with type (pageview or event), path, and optionally name, properties, ip, user_agent, referrer and utm_* tags
     * @return array<string, mixed> accepted and skipped counts
     */
    public function analyticsEvents(int $siteId, array $events): array
    {
        return $this->data('POST', '/analytics/sites/'.$siteId.'/events', ['events' => $events]);
    }

    /**
     * List recent deploys, newest first.
     *
     * @param  int  $limit  1–100
     * @return list<array<string, mixed>>
     */
    public function deployments(int $limit = 25): array
    {
        return array_values($this->data('GET', '/deployments?limit='.max(1, min(100, $limit))));
    }

    /**
     * Get one deploy.
     *
     * @param  int  $deploymentId
     * @return array<string, mixed>
     */
    public function deployment(int $deploymentId): array
    {
        return $this->data('GET', '/deployments/'.$deploymentId);
    }

    /**
     * Get a deploy's log (its tail) and status.
     *
     * @param  int  $deploymentId
     * @return array{deployment_id: int, status: string, log: string}
     */
    public function log(int $deploymentId): array
    {
        /** @var array{deployment_id: int, status: string, log: string} $log */
        $log = $this->data('GET', '/deployments/'.$deploymentId.'/log');

        return $log;
    }

    /**
     * Deploy an environment, optionally a branch, tag or commit instead of its repository's branch.
     *
     * @param  string  $environmentId
     * @param  string|null  $ref
     * @return array<string, mixed> the queued deploy
     */
    public function deploy(string $environmentId, ?string $ref = null): array
    {
        return $this->data('POST', '/environments/'.rawurlencode($environmentId).'/deploy', $ref === null ? null : ['ref' => $ref]);
    }

    /**
     * Roll back to the release a deploy shipped.
     *
     * @param  int  $deploymentId
     * @return array<string, mixed> the rollback deploy
     */
    public function rollback(int $deploymentId): array
    {
        $data = $this->data('POST', '/deployments/'.$deploymentId.'/rollback');

        return is_array($data['deployment'] ?? null) ? $data['deployment'] : $data;
    }

    /**
     * Replace an environment's variables with .env text. Returns the result: status "applied" with a count, or
     * "pending_approval" with a change ID when the environment needs a second person.
     *
     * @param  string  $environmentId
     * @param  string  $dotenv
     * @return array<string, mixed>
     */
    public function replaceVariables(string $environmentId, string $dotenv): array
    {
        return $this->data('PUT', '/environments/'.rawurlencode($environmentId).'/variables', ['variables' => $dotenv]);
    }

    /**
     * Poll a deploy until it finishes, then return it.
     *
     * @param  int  $deploymentId
     * @param  int  $timeoutSeconds
     * @param  int  $intervalSeconds
     * @return array<string, mixed>
     *
     * @throws ApiException when it hasn't finished in time
     */
    public function waitForDeployment(int $deploymentId, int $timeoutSeconds = 1800, int $intervalSeconds = 5): array
    {
        $deadline = time() + $timeoutSeconds;
        do {
            $deployment = $this->deployment($deploymentId);
            if (in_array($deployment['status'] ?? null, self::FINISHED, true)) {
                return $deployment;
            }
            if ($intervalSeconds > 0) {
                sleep($intervalSeconds);
            }
        } while (time() < $deadline);

        throw new ApiException("Deploy #{$deploymentId} didn't finish within {$timeoutSeconds} seconds.", 408);
    }

    /**
     * Call the API and return the response's `data`.
     *
     * @param  string  $method
     * @param  string  $path  under /api/v1
     * @param  array<string, mixed>|null  $body
     * @return array<array-key, mixed>
     *
     * @throws ApiException
     */
    private function data(string $method, string $path, ?array $body = null): array
    {
        $response = ($this->transport)($method, rtrim($this->baseUrl, '/').'/api/v1'.$path, [
            'Accept' => 'application/json', 'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->token, 'User-Agent' => 'buildpusher-php-sdk/1.0',
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];
        if ($response['status'] >= 400) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : "The API answered HTTP {$response['status']}.";

            throw new ApiException($message, $response['status'], $decoded);
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * Send a request with PHP's HTTP stream wrapper (no extensions or packages needed).
     *
     * @param  string  $method
     * @param  string  $url
     * @param  array<string, string>  $headers
     * @param  string|null  $body
     * @return array{status: int, body: string}
     */
    private static function streamTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 30]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new ApiException("Couldn't reach {$url}.", 0);
        }
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return ['status' => $status, 'body' => $response];
    }
}
