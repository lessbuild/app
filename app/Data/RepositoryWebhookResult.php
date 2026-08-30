<?php

namespace App\Data;

use App\Models\Build;

class RepositoryWebhookResult
{
    public const QUEUED = 'queued';

    public const PENDING = 'pending';

    public const DUPLICATE = 'duplicate';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $status,
        public readonly ?Build $build = null,
    ) {}
}
