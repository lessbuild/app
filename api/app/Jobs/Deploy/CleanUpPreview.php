<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Jobs\Infrastructure\RemoveWebsitePlacement;
use App\Models\Preview;
use App\Services\Deploy\Previews;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Removes a closed preview's stack from its server: its Valkey containers and their volumes, then its website (process
 * units, files, Caddy site and database). A failure is recorded on the preview for someone to retry, rather than
 * retried blindly.
 */
final class CleanUpPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Retries go through the preview page, which requeues the cleanup.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new CleanUpPreview instance.
     *
     * Cleans up one closed preview.
     *
     * @param  int  $previewId  The preview whose cleanup was queued.
     */
    public function __construct(public readonly int $previewId) {}

    /**
     * Claim the queued cleanup, remove the stack, and record the outcome. A pull request that reopened meanwhile gets its
     * stack back once the old one is gone.
     *
     * @param  ServerShell  $shell
     * @param  Previews  $previews
     * @return void
     */
    public function handle(ServerShell $shell, Previews $previews): void
    {
        $preview = $this->claim();
        if ($preview === null) {
            return;
        }
        try {
            $this->removeStack($preview, $shell);
        } catch (Throwable $exception) {
            report($exception);
            $preview->forceFill(['cleanup_status' => Preview::CLEANUP_FAILED, 'cleanup_error' => Str::limit($exception->getMessage(), 2000)])->save();

            return;
        }
        $preview->forceFill(['cleanup_status' => Preview::CLEANUP_SUCCEEDED, 'cleanup_error' => null])->save();
        $previews->reopenAfterCleanup($preview);
    }

    /**
     * Mark the queued cleanup running, or return null when it isn't queued any more.
     *
     * @return Preview|null
     */
    private function claim(): ?Preview
    {
        return DB::transaction(function (): ?Preview {
            $preview = Preview::query()->lockForUpdate()->find($this->previewId);
            if ($preview === null || $preview->cleanup_status !== Preview::CLEANUP_QUEUED) {
                return null;
            }
            $preview->forceFill(['cleanup_status' => Preview::CLEANUP_RUNNING, 'cleanup_attempts' => $preview->cleanup_attempts + 1])->save();

            return $preview;
        });
    }

    /**
     * Remove the preview's Valkey containers and volumes, then delete its website and remove it from the server.
     *
     * @param  Preview  $preview
     * @param  ServerShell  $shell
     * @return void
     */
    private function removeStack(Preview $preview, ServerShell $shell): void
    {
        $website = $preview->website;
        $server = $website?->server;
        $containers = $preview->environment?->resources()->where('type', 'valkey')->where('is_managed', true)->get()
            ->map(fn ($resource): string => (string) ($resource->configuration['container_name'] ?? ''))
            ->filter(fn (string $name): bool => preg_match('/\Abuildpusher-valkey-[a-z0-9_-]+\z/D', $name) === 1)->values()->all() ?? [];
        if ($server !== null && $containers !== []) {
            $commands = ['if command -v docker >/dev/null 2>&1; then'];
            foreach ($containers as $container) {
                $commands[] = '    docker rm --force '.escapeshellarg($container).' >/dev/null 2>&1 || true';
                $commands[] = '    docker volume rm '.escapeshellarg("{$container}-data").' >/dev/null 2>&1 || true';
            }
            $commands[] = 'fi';
            $result = $shell->run($server, implode("\n", $commands));
            if (! $result->successful()) {
                throw new RuntimeException('Couldn’t remove the preview’s caches from '.$server->label().': '.$result->combined());
            }
        }
        if ($website === null) {
            return;
        }
        if (! $website->trashed()) {
            $website->delete();
        }
        if ($website->server_id !== null) {
            (new RemoveWebsitePlacement($website->id, $website->server_id, $website->deployment_slug))->handle($shell);
        }
    }
}
