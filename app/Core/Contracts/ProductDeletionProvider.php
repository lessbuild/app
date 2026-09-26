<?php

namespace App\Core\Contracts;

use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionPreview;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Data\Deletion\ProductDeletionTarget;

/** Product-owned cleanup; accepted Core intent never authorizes remote infrastructure destruction. */
interface ProductDeletionProvider
{
    public function product(): string;

    public function inspect(ProductDeletionTarget $target): ProductDeletionPreview;

    /** Persist a local fence and stop new activity; do not erase records in this phase. */
    public function prepare(ProductDeletionAttempt $attempt): ProductDeletionResult;

    /** Idempotent cleanup after every product has acknowledged its preparation. */
    public function purge(ProductDeletionAttempt $attempt): ProductDeletionResult;
}
