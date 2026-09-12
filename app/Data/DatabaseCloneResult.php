<?php

namespace App\Data;

use App\Models\DatabaseClone;

class DatabaseCloneResult
{
    public const QUEUED = 'queued';

    public const CONFIRMATION_MISMATCH = 'confirmation_mismatch';

    /**
     * Carry the result of a database clone request without coupling the operation to an HTTP response.
     *
     * @param  'queued'|'confirmation_mismatch'  $status  Outcome of the clone request.
     * @param  DatabaseClone|null  $clone  Newly queued clone, or null when confirmation was rejected.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?DatabaseClone $clone = null,
    ) {}
}
