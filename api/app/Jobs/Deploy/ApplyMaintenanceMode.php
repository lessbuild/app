<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Environment;
use App\Models\Server;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

final class ApplyMaintenanceMode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two tries: the server may be mid-deploy.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 15;

    /**
     * Create a new ApplyMaintenanceMode instance.
     *
     * Puts an environment's websites into maintenance mode or brings them back.
     *
     * @param  string  $environmentId  The environment.
     * @param  bool  $down  true for maintenance mode, false to bring the sites back.
     */
    public function __construct(public readonly string $environmentId, public readonly bool $down) {}

    /**
     * Run `php artisan down` (with the secret that lets the team in) or `php artisan up` in each deployed website's
     * current release (sites without artisan are left alone). Laravel keeps the state in shared storage, so it survives deploys.
     *
     * @param  ServerShell  $shell
     * @return void
     */
    public function handle(ServerShell $shell): void
    {
        $environment = Environment::query()->find($this->environmentId);
        if ($environment === null) {
            return;
        }
        foreach ($environment->deployedWebsites() as $website) {
            if ($website->server === null || $website->server->provisioning_status !== Server::STATUS_ACTIVE) {
                continue;
            }
            $artisan = $this->down
                ? 'php artisan down --retry=60 --secret='.escapeshellarg((string) $environment->maintenance_secret)
                : 'php artisan up';
            $current = escapeshellarg($website->deploymentPath('current'));
            $command = "if [ -f {$current}/artisan ]; then cd -- {$current} && sudo -u www-data {$artisan}; fi";
            $result = $shell->run($website->server, $command);
            if (! $result->successful()) {
                throw new RuntimeException("{$website->name}: ".str(trim($result->errorOutput ?: $result->output))->limit(300));
            }
        }
        $environment->forceFill(['maintenance_error' => null])->save();
    }

    /**
     * Record why the change failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Environment::query()->whereKey($this->environmentId)->update(['maintenance_error' => str($exception->getMessage())->limit(1000)->toString()]);
    }
}
