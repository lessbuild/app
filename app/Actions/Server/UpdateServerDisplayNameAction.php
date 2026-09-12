<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ActivityRecorder;

class UpdateServerDisplayNameAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Save a server's visible label and record activity only when it changes.
     *
     * @param  Server  $server  Server whose public display label is being changed.
     * @param  string|null  $displayName  Validated and normalized label, or null to use the technical name.
     */
    public function handle(Server $server, ?string $displayName): void
    {
        $oldLabel = $server->label;
        if ($displayName === $server->name) {
            $displayName = null;
        }

        $server->update(['display_name' => $displayName]);
        if ($oldLabel !== $server->label) {
            $this->activity->record(
                $server,
                $server->user_id,
                'server',
                "Server display name changed from \"{$oldLabel}\" to \"{$server->label}\".",
            );
        }
    }
}
