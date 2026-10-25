<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Contracts\Infrastructure\ResizableServerProvider;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\RunServerResize;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\ServerProviderResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ResizeServer
{
    /**
     * Create a new ResizeServer instance.
     *
     * @param  ServerProviderResolver  $providers  Resolves the server's cloud client.
     * @param  RecordAuditEntry  $audit  Records the resize.
     */
    public function __construct(private readonly ServerProviderResolver $providers, private readonly RecordAuditEntry $audit) {}

    /**
     * Start resizing a cloud server to a new size (optionally growing its disk): check it's a resizable, active cloud
     * server and the size is a real, different one, mark it resizing, and queue the work (power off, resize, power on).
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  string  $size  The new size slug from the provider's catalog.
     * @param  bool  $resizeDisk  Also grow the disk (permanent), or change CPU and memory only.
     * @return void
     */
    public function handle(User $actor, Server $server, string $size, bool $resizeDisk): void
    {
        Gate::forUser($actor)->authorize('update', $server);
        if ($server->provider === null || $server->identifier === null) {
            throw ValidationException::withMessages(['size' => __('Only a server created through a cloud provider can be resized here.')]);
        }
        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['size' => __('The server is busy. Resize it once it’s active.')]);
        }
        $client = $this->providers->resolve($server->provider);
        if (! $client instanceof ResizableServerProvider) {
            throw ValidationException::withMessages(['size' => __(':provider servers can’t be resized from here yet.', ['provider' => $client->name()])]);
        }
        if ($size === '' || $size === $server->size) {
            throw ValidationException::withMessages(['size' => __('Choose a different size.')]);
        }
        try {
            $slugs = array_values(array_filter(array_map(fn (array $entry): ?string => is_scalar($entry['slug'] ?? null) ? (string) $entry['slug'] : null, $client->sizes())));
        } catch (Throwable) {
            $slugs = [];
        }
        if ($slugs !== [] && ! in_array($size, $slugs, true)) {
            throw ValidationException::withMessages(['size' => __('That size isn’t in the provider’s list.')]);
        }

        $server->forceFill(['provisioning_status' => Server::STATUS_RESIZING])->save();
        RunServerResize::dispatch($server->id, $size, $resizeDisk);
        $this->audit->handle(AuditAction::ServerResized, $actor, $server->account_id, ['server' => $server->name, 'size' => $size]);
    }
}
