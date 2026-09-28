<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Build;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Watches a website's health for a while after a deploy goes live (the environment's observation minutes): a failing
 * health check fails the observation and, if the environment asks for it, rolls back to the previous release.
 */
class DeploymentObserver
{
    /**
     * Observes deploys after they go live.
     *
     * @param  Factory  $http  Requests the health URL.
     * @param  Deployments  $deployments  Rolls back when the environment asks for it.
     */
    public function __construct(private readonly Factory $http, private readonly Deployments $deployments) {}

    /**
     * Checks the website's health once for an observed build: a newer live build supersedes it, a failed check fails it
     * (and may roll back), and a healthy check past the deadline passes it.
     *
     * @return string passed, failed, superseded or observing
     */
    public function check(Build $build): string
    {
        $website = $build->website;
        $newer = Build::query()->where('website_id', $build->website_id)->where('id', '>', $build->id)->whereNotNull('activated_at')->exists();
        if ($newer) {
            return $this->finish($build, 'superseded');
        }
        $url = 'https://'.$website->url.($website->health_check_enabled ? $website->health_check_path : '/');
        try {
            $healthy = $this->http->connectTimeout(5)->timeout(15)->withoutRedirecting()->get($url)->successful();
            $error = $healthy ? null : (string) __('The health check at :url didn’t answer with a success status.', ['url' => $url]);
        } catch (Throwable) {
            [$healthy, $error] = [false, (string) __('The health check at :url couldn’t be reached.', ['url' => $url])];
        }
        if (! $healthy) {
            $this->finish($build, 'failed', $error);
            $this->deployments->rollBackAutomatically($build->loadMissing('environment'));

            return 'failed';
        }

        return $build->observation_deadline_at !== null && $build->observation_deadline_at->isPast() ? $this->finish($build, 'passed') : 'observing';
    }

    /**
     * Records the observation's outcome.
     */
    private function finish(Build $build, string $status, ?string $error = null): string
    {
        $build->forceFill(['observation_status' => $status, 'observation_error' => $error])->save();

        return $status;
    }
}
