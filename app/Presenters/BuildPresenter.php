<?php

namespace App\Presenters;

use App\Models\Build;

final readonly class BuildPresenter
{
    /** @param Build $build The build whose release metadata will be displayed. */
    public function __construct(private Build $build) {}

    /** @return string|null The first 12 revision characters, or null before a revision is known. */
    public function shortRevision(): ?string
    {
        return $this->build->revision ? substr($this->build->revision, 0, 12) : null;
    }

    /** @return string The recorded release name, falling back to the UTC timestamp and build ID. */
    public function releaseIdentifier(): string
    {
        return $this->build->release_name
            ?? sprintf('%s-build-%d', ($this->build->created_at ?? now())->utc()->format('YmdHis'), $this->build->getKey());
    }
}
