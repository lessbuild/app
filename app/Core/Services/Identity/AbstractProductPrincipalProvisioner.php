<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductPrincipalProvisioner;
use App\Core\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared account synchronization mechanics; modules supply their local account details. */
abstract class AbstractProductPrincipalProvisioner implements ProductPrincipalProvisioner
{
    /** @return class-string<Model> */
    abstract protected function userModel(): string;

    abstract protected function product(): string;

    /** @return array<string, mixed> */
    protected function additionalAttributes(PlatformUser $platformUser): array
    {
        return [];
    }

    protected function afterProvision(Model $principal, PlatformUser $platformUser): void {}

    public function synchronizeMappedPrincipal(string $sourceId, PlatformUser $platformUser): bool
    {
        $model = $this->userModel();
        $principal = $model::query()
            ->whereKey($sourceId)
            ->where('platform_user_id', (string) $platformUser->getAuthIdentifier())
            ->first();

        if ($principal === null) {
            return false;
        }

        $this->synchronize($principal, $platformUser);

        return true;
    }

    public function provision(PlatformUser $platformUser): string
    {
        $product = $this->product();
        $model = $this->userModel();

        abort_unless(
            Schema::connection($product)->hasColumn('users', 'platform_user_id'),
            503,
            "The {$product} shared-auth migration is required.",
        );

        return DB::connection($product)->transaction(function () use ($model, $platformUser): string {
            $principal = $model::query()
                ->where('platform_user_id', (string) $platformUser->getAuthIdentifier())
                ->first();

            if ($principal !== null) {
                $this->synchronize($principal, $platformUser);

                return (string) $principal->getKey();
            }

            $emailInUse = $model::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $platformUser->email))])
                ->exists();

            abort_if($emailInUse, 409, 'A product account with this email needs explicit identity reconciliation.');

            $principal = new $model;
            $attributes = array_merge([
                'name' => $platformUser->name,
                'email' => $platformUser->email,
                'password' => $platformUser->getAuthPassword(),
                'email_verified_at' => $platformUser->email_verified_at?->format('Y-m-d H:i:s'),
                'platform_user_id' => (string) $platformUser->getAuthIdentifier(),
                'created_at' => now(),
                'updated_at' => now(),
            ], $this->additionalAttributes($platformUser));
            $principal->setRawAttributes($attributes);
            $principal->save();

            $this->afterProvision($principal, $platformUser);

            return (string) $principal->getKey();
        });
    }

    private function synchronize(Model $principal, PlatformUser $platformUser): void
    {
        $password = (string) $platformUser->getAuthPassword();
        $updates = [];

        if ($principal->name !== $platformUser->name) {
            $updates['name'] = $platformUser->name;
        }

        if (mb_strtolower((string) $principal->email) !== mb_strtolower((string) $platformUser->email)) {
            $updates['email'] = $platformUser->email;
        }

        if ((string) $principal->getAuthPassword() !== $password) {
            $updates['password'] = $password;
        }

        if ($platformUser->email_verified_at !== null && $principal->email_verified_at === null) {
            $updates['email_verified_at'] = $platformUser->email_verified_at->format('Y-m-d H:i:s');
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            $principal->newQuery()->whereKey($principal->getKey())->update($updates);
        }
    }
}
