<?php

declare(strict_types=1);

namespace BuildPusher\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** php artisan buildpusher:deploy: mark a release in BuildPusher Monitoring, so errors and traces line up with it. */
final class RecordDeployment extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    protected $signature = 'buildpusher:deploy {version? : The release (defaults to BUILDPUSHER_RELEASE)} {--commit= : The commit SHA} {--note= : A note}';

    /**
     * The command's description.
     *
     * @var string
     */
    protected $description = 'Tell BuildPusher Monitoring a release went live';

    /**
     * Record the deployment. Returns 0 when it was recorded.
     *
     * @return int
     */
    public function handle(): int
    {
        $version = (string) ($this->argument('version') ?? config('buildpusher.release') ?? '');
        $token = (string) config('buildpusher.token');
        if ($version === '' || $token === '') {
            $this->error('Set BUILDPUSHER_TOKEN, and pass a version or set BUILDPUSHER_RELEASE.');

            return self::FAILURE;
        }
        $response = Http::timeout(10)->withToken($token)->acceptJson()->post(rtrim((string) config('buildpusher.endpoint'), '/').'/deployments', array_filter([
            'deployment_id' => (string) Str::uuid(),
            'version' => $version,
            'service' => (string) config('buildpusher.service'),
            'commit_sha' => $this->option('commit') ?: null,
            'note' => $this->option('note') ?: null,
            'deployed_at' => now('UTC')->toIso8601ZuluString(),
        ]));
        if ($response->failed()) {
            $this->error('BuildPusher answered HTTP '.$response->status().': '.(string) $response->json('message', $response->body()));

            return self::FAILURE;
        }
        $this->info("Recorded {$version}.");

        return self::SUCCESS;
    }
}
