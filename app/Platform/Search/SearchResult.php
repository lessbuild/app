<?php

declare(strict_types=1);

namespace App\Platform\Search;

final readonly class SearchResult
{
    /**
     * One row in the command palette's search results.
     *
     * @param  string  $title  The main text, such as a project name.
     * @param  string  $url  Where choosing the result goes.
     * @param  ?string  $subtitle  Secondary text shown under the title, such as an email address.
     * @param  ?string  $type  What kind of thing the result is ("Project", "Member"), shown as a label.
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?string $subtitle = null,
        public ?string $type = null,
    ) {}
}
