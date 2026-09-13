<?php

namespace App\Data;

use App\Models\Environment;

final readonly class DeploymentObservationConfiguration
{
    /**
     * Carry the non-secret deployment observation target captured in a build.
     *
     * @param  int  $durationMinutes  Bounded observation window length.
     * @param  int  $websiteId  Website target captured for the deployment.
     * @param  int  $serverId  Managed server target captured for the deployment.
     * @param  string  $websiteUrl  Hostname captured before remote execution.
     * @param  string  $healthCheckPath  Relative HTTP path captured before remote execution.
     */
    public function __construct(
        public int $durationMinutes,
        public int $websiteId,
        public int $serverId,
        public string $websiteUrl,
        public string $healthCheckPath,
    ) {}

    /**
     * Parse the encrypted build-payload section without enabling legacy deployments.
     *
     * @param  array<string, mixed>|null  $payload  Captured environment payload.
     * @return self|null The valid opt-in configuration, or null when observation is disabled or malformed.
     */
    public static function fromPayload(?array $payload): ?self
    {
        $configuration = $payload['post_deployment_observation'] ?? null;
        if (! is_array($configuration)) {
            return null;
        }

        $duration = filter_var($configuration['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $websiteId = filter_var($configuration['website_id'] ?? null, FILTER_VALIDATE_INT);
        $serverId = filter_var($configuration['server_id'] ?? null, FILTER_VALIDATE_INT);
        $url = $configuration['url'] ?? null;
        $path = $configuration['path'] ?? null;

        if ($duration === false || ! in_array($duration, Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES, true)
            || $websiteId === false || $websiteId < 1
            || $serverId === false || $serverId < 1
            || ! is_string($url) || trim($url) === ''
            || ! is_string($path) || ! preg_match("#\A/(?!/)[A-Za-z0-9._~%!$&'()*+,;=:@/\\-]*\z#D", $path)) {
            return null;
        }

        return new self(
            durationMinutes: $duration,
            websiteId: $websiteId,
            serverId: $serverId,
            websiteUrl: trim($url),
            healthCheckPath: $path,
        );
    }

    /** @return array{duration_minutes: int, website_id: int, server_id: int, url: string, path: string} */
    public function toArray(): array
    {
        return [
            'duration_minutes' => $this->durationMinutes,
            'website_id' => $this->websiteId,
            'server_id' => $this->serverId,
            'url' => $this->websiteUrl,
            'path' => $this->healthCheckPath,
        ];
    }
}
