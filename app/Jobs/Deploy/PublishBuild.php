<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Events\Deploy\DeployStarted;
use App\Models\Build;
use App\Services\Deploy\DeploymentScript;
use App\Services\Infrastructure\RemoteScriptRunner;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Uploads a queued build's deployment script to the website's server and starts it; the script then reports in by callback. */
final class PublishBuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Starting the deploy script can fail on a busy server, so it gets three tries.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 15;

    /**
     * Create a new PublishBuild instance.
     *
     * Starts a queued deploy on its website's server.
     *
     * @param  int  $buildId  The queued build to start.
     */
    public function __construct(public readonly int $buildId) {}

    /**
     * Claim the build, names its release and uploads the deploy script to run in the background on the server. The
     * script reports its progress back; if uploading fails, the build is put back in the queue so the retry starts it
     * cleanly.
     *
     * @param  RemoteScriptRunner  $runner
     * @param  DeploymentScript  $script
     * @return void
     */
    public function handle(RemoteScriptRunner $runner, DeploymentScript $script): void
    {
        $build = Build::query()->with(['website.server', 'repository.provider'])->find($this->buildId);
        if ($build === null || $build->status !== Build::STATUS_QUEUED) {
            return;
        }
        $release = $build->releaseIdentifier();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        if (Build::query()->whereKey($build->id)->where('status', Build::STATUS_QUEUED)->update([
            'status' => Build::STATUS_DEPLOYING, 'started_at' => $now, 'last_heartbeat_at' => $now, 'failure_message' => null, 'setup_stage' => 0,
            'release_name' => $release, 'release_path' => "/var/www/{$build->website->deployment_slug}/releases/{$release}",
        ]) === 0) {
            return;
        }
        $build->refresh();
        $server = $build->website->server;
        if ($server === null || ! $build->repository->isDeploymentReady()) {
            app(FinishBuild::class)->handle($build, Build::STATUS_FAILED, 'The website, its server or the repository’s Git provider isn’t ready.');

            return;
        }
        try {
            $process = $runner->start($server, $script->render($build), "lessbuild-deployment-{$build->id}");
        } catch (Throwable $exception) {
            // Uploading can fail on a busy server: put the build back so the retry starts it cleanly.
            Build::query()->whereKey($build->id)->where('status', Build::STATUS_DEPLOYING)->update(['status' => Build::STATUS_QUEUED, 'started_at' => null, 'release_name' => null, 'release_path' => null]);

            throw $exception;
        }
        if (Build::query()->whereKey($build->id)->where('status', Build::STATUS_DEPLOYING)
            ->update(['status' => Build::STATUS_RUNNING, 'remote_process_id' => $process['id'], 'remote_process_path' => $process['path']]) === 1) {
            DeployStarted::dispatch($build->refresh());
        }
    }

    /**
     * Mark a build that never started as failed, with the reason.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $build = Build::query()->whereKey($this->buildId)->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_DEPLOYING])->first();
        if ($build !== null) {
            app(FinishBuild::class)->handle($build, Build::STATUS_FAILED, str($exception->getMessage())->limit(2000)->toString());
        }
    }
}
