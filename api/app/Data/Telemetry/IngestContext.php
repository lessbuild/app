<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Enums\IngestSource;

final readonly class IngestContext
{
    /**
     * Create a new IngestContext instance.
     *
     * How a telemetry batch reached us, which decides how it is deduplicated and recorded.
     *
     * @param  IngestSource  $source  Our JSON events or one of the OTLP signals.
     * @param  bool  $explicitBatch  Whether the client named the batch. Named batches are deduplicated by their ID; OTLP
     *                               exports have no ID, so identical content is what makes a retry a duplicate.
     * @param  ?int  $tokenId  The ingest token that authenticated the request, recorded on the receipt.
     */
    public function __construct(
        public IngestSource $source = IngestSource::Json,
        public bool $explicitBatch = true,
        public ?int $tokenId = null,
    ) {}
}
