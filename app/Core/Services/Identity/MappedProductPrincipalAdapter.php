<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductPrincipalAdapter;
use App\Core\Models\PlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class MappedProductPrincipalAdapter implements ProductPrincipalAdapter
{
    /**
     * @param  class-string<Authenticatable>  $model
     */
    public function __construct(
        private readonly string $product,
        private readonly string $model,
        private readonly LegacyIdentityResolver $identities,
    ) {
        if (! is_a($model, Model::class, true) || ! is_a($model, Authenticatable::class, true)) {
            throw new InvalidArgumentException('A product principal model must be an Eloquent authenticatable model.');
        }
    }

    public function resolve(PlatformUser $user): ?Authenticatable
    {
        $sourceIds = $this->identities->sourceIdsFor($user, $this->product);

        // Never guess when an account has multiple source records in one module.
        if (count($sourceIds) !== 1) {
            return null;
        }

        $principal = ($this->model)::query()->whereKey($sourceIds[0])->first();

        return $principal instanceof Authenticatable ? $principal : null;
    }
}
