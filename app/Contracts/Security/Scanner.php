<?php

declare(strict_types=1);

namespace App\Contracts\Security;

use App\Data\Security\Finding;
use App\Models\Project;

/** One kind of Security check, such as dependencies or domains, run against a project. */
interface Scanner
{
    /**
     * Get the scanner's kind, stored on scans and used as the findings' source.
     *
     * @return string
     */
    public function kind(): string;

    /**
     * Get the scanner's name, as shown on the overview.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Get the entitlement flag a plan needs to run it, or null when every plan can.
     *
     * @return string|null
     */
    public function flag(): ?string;

    /**
     * Check the project and report what's wrong, grouped by what was looked at (e.g. website:12). A scope returned
     * with no findings means it was checked and is clean; scopes not returned at all are treated as gone.
     *
     * @param  Project  $project
     * @return array<string, list<Finding>>
     */
    public function scan(Project $project): array;
}
