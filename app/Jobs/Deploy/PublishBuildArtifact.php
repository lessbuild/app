<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Models\Build;
use App\Services\Deploy\BuildServers;
use App\Services\Deploy\DeploymentScript;
use App\Services\Infrastructure\RemoteScriptRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Starts a build's release part on its website's server once the build server has uploaded the built release. */
final class PublishBuildArtifact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Starting the script can fail on a busy server, so it gets three tries.
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
     * Create a new PublishBuildArtifact instance.
     *
     * @param  int  $buildId  The build whose release is uploaded.
     */
    public function __construct(public readonly int $buildId) {}

    /**
     * Upload the release part, with a presigned download URL for the built release, to the website's server and start
     * it in the background.
     *
     * @param  RemoteScriptRunner  $runner
     * @param  DeploymentScript  $script
     * @param  BuildServers  $buildServers
     * @return void
     */
    public function handle(RemoteScriptRunner $runner, DeploymentScript $script, BuildServers $buildServers): void
    {
        $build = Build::query()->with(['website.server', 'repository.provider', 'environment.artifactBucket'])->find($this->buildId);
        if ($build === null || $build->status !== Build::STATUS_RUNNING || $build->build_phase !== 'release' || $build->remote_process_path !== null) {
            return;
        }
        $server = $build->website->server ?? throw new RuntimeException('The website has no server.');
        $bucket = $build->environment->artifactBucket ?? throw new RuntimeException('The environment no longer has a storage bucket for builds.');
        $process = $runner->start($server, $script->renderRelease($build, $buildServers->downloadUrl($bucket, (string) $build->artifact_key)), "lessbuild-deployment-{$build->id}");
        Build::query()->whereKey($build->id)->where('status', Build::STATUS_RUNNING)
            ->update(['remote_process_id' => $process['id'], 'remote_process_path' => $process['path'], 'last_heartbeat_at' => now()]);
    }

    /**
     * Fail the build when its release part couldn't start.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $build = Build::query()->whereKey($this->buildId)->where('status', Build::STATUS_RUNNING)->first();
        if ($build !== null) {
            app(FinishBuild::class)->handle($build, Build::STATUS_FAILED, str('The built release couldn’t start on the website’s server: '.$exception->getMessage())->limit(2000)->toString());
        }
    }
}
