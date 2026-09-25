<?php

namespace App\Core\Data\Help;

/** @param array<string, mixed> $document */
final readonly class ProductApiReference
{
    public function __construct(
        public string $product,
        public string $baseUrl,
        public string $openApiUrl,
        public string $ingestUrl,
        public array $document,
    ) {}
}
