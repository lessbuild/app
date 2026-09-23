<?php

namespace App\Core\Services;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;

final class ProjectProductLinks
{
    public function __construct(
        private readonly ProjectProductLinkRegistry $links,
        private readonly WorkspaceProjectAccess $access,
    ) {}

    /** @param list<string> $products
     * @return array<string, string>
     */
    public function forProject(PlatformUser $user, Project $project, array $products): array
    {
        $urls = [];

        foreach (array_unique($products) as $product) {
            if (! $this->access->canAccessProductResource($user, $project, $product)) {
                continue;
            }

            $url = $this->links->get($product)?->resolve($user, $project);

            if ($url !== null) {
                $urls[$product] = $url;
            }
        }

        return $urls;
    }
}
