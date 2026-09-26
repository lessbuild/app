<?php

declare(strict_types=1);

namespace App\Platform\Search;

final readonly class SearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $subtitle = null,
        public ?string $type = null,
    ) {}
}
