<?php

namespace App\Actions\Server;

use App\Services\ServerTroubleshootingFrameStore;

class PruneServerTroubleshootingFramesAction
{
    public function __construct(private readonly ServerTroubleshootingFrameStore $frames) {}

    /** Remove a bounded batch of expired encrypted input/output frames. */
    public function handle(int $limit = 500): int
    {
        return $this->frames->prune($limit);
    }
}
