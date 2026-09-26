<?php

namespace App\Core\Data\Search;

final readonly class WorkspaceSearchResult
{
    public function __construct(
        public string $type,
        public string $title,
        public ?string $subtitle,
        public string $url,
    ) {}

    /** @return array{type:string,title:string,subtitle:?string,url:string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'url' => $this->url,
        ];
    }
}
