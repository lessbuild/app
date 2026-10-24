<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Infrastructure\ServerAlerts;
use Illuminate\Database\Migrations\Migration;

/** Give servers that already exist Buildpusher's built-in disk and memory alerts straight away (new readings would otherwise add them only on the next metric). */
return new class extends Migration
{
    /**
     * Seed the built-in rules for every active server.
     *
     * @return void
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $alerts = app(ServerAlerts::class);
        Server::query()->where('provisioning_status', Server::STATUS_ACTIVE)->chunkById(100, function ($servers) use ($alerts): void {
            foreach ($servers as $server) {
                $alerts->ensureBuiltIn($server);
            }
        });
    }

    /**
     * Remove the built-in rules again.
     *
     * @return void
     */
    public function down(): void
    {
        App\Models\ServerAlertRule::query()->where('built_in', true)->delete();
    }
};
