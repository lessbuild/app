<?php

namespace App\Data;

use App\Models\Build;

class RepositoryWebhookResult
{
    public const QUEUED = 'queued';

    public const PENDING = 'pending';

    public const DUPLICATE = 'duplicate';

    public const UNAVAILABLE = 'unavailable';

    /**
     * Carry the outcome of accepting a repository webhook.
     *
     * @param  'queued'|'pending'|'duplicate'|'unavailable'  $status  Decision made by the corresponding action.
     * @param  Build|null  $build  Newly queued build, or null when the action did not create one.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?Build $build = null,
    ) {}
}
