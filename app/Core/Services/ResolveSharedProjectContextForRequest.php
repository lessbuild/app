<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProductProjectContextState;
use App\Core\Data\Projects\ResolvedProductProjectContext;
use App\Core\Services\Identity\ResolvePlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class ResolveSharedProjectContextForRequest
{
    public function __construct(
        private readonly ResolvePlatformUser $platformUsers,
        private readonly ResolveProductProjectContext $contexts,
    ) {}

    public function handle(
        Request $request,
        string $product,
        ?string $sourceResourceType,
        string|int|null $sourceResourceId,
    ): ResolvedProductProjectContext {
        $projectId = $request->query('context_project');
        $environmentId = $request->query('context_environment');

        if (($projectId === null || $projectId === '') && ($environmentId === null || $environmentId === '')) {
            return new ResolvedProductProjectContext(ProductProjectContextState::None);
        }

        $principal = $request->user();

        if (! $principal instanceof Authenticatable) {
            return new ResolvedProductProjectContext(ProductProjectContextState::Unavailable);
        }

        $user = $this->platformUsers->resolve($principal, $product);

        if ($user === null) {
            return new ResolvedProductProjectContext(ProductProjectContextState::Unavailable);
        }

        return $this->contexts->handle(
            $user,
            $product,
            $projectId,
            $environmentId,
            $sourceResourceType,
            $sourceResourceId,
        );
    }
}
