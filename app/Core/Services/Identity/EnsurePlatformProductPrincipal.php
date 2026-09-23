<?php

namespace App\Core\Services\Identity;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Services\CreateWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Provision a product-local identity on first Core-authenticated product access. */
final class EnsurePlatformProductPrincipal
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        'deployer' => DeployerUser::class,
        'monitor' => MonitorUser::class,
        'analytics' => AnalyticsUser::class,
    ];

    public function handle(string $product, PlatformUser $platformUser): void
    {
        $model = self::MODELS[$product] ?? null;
        abort_if($model === null, 404);

        // Existing and unresolved imported accounts are never linked by email.
        $existingMapping = LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', 'user')
            ->where('canonical_entity', 'user')
            ->where('canonical_id', (string) $platformUser->getAuthIdentifier())
            ->first();

        if ($existingMapping !== null) {
            if ($existingMapping->status === 'reconciled') {
                $principal = $model::query()
                    ->whereKey($existingMapping->source_id)
                    ->where('platform_user_id', (string) $platformUser->getAuthIdentifier())
                    ->first();

                if ($principal !== null) {
                    $this->synchronizeProvisionedAccount($product, $principal, $platformUser);
                }
            }

            return;
        }

        abort_unless(
            Schema::connection($product)->hasColumn('users', 'platform_user_id'),
            503,
            "The {$product} shared-auth migration is required.",
        );

        $principalId = DB::connection($product)->transaction(function () use ($model, $product, $platformUser): string {
            /** @var Model|null $principal */
            $principal = $model::query()
                ->where('platform_user_id', (string) $platformUser->getAuthIdentifier())
                ->first();

            if ($principal !== null) {
                $this->synchronizeProvisionedAccount($product, $principal, $platformUser);

                return (string) $principal->getKey();
            }

            $emailInUse = $model::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $platformUser->email))])
                ->exists();

            abort_if($emailInUse, 409, 'A product account with this email needs explicit identity reconciliation.');

            /** @var Model $principal */
            $principal = new $model;
            $attributes = [
                'name' => $platformUser->name,
                'email' => $platformUser->email,
                'password' => $platformUser->getAuthPassword(),
                'email_verified_at' => $platformUser->email_verified_at?->format('Y-m-d H:i:s'),
                'platform_user_id' => (string) $platformUser->getAuthIdentifier(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($product === 'deployer') {
                $attributes['auth_type'] = 'platform';
                $attributes['password_set_at'] = $platformUser->password_set_at;
            }

            $principal->setRawAttributes($attributes);
            $principal->save();

            if ($product === 'monitor') {
                $workspaceName = trim((string) $platformUser->name)."'s workspace";

                if (Schema::connection('core')->hasTable('workspace_memberships')) {
                    $workspaceName = $platformUser->workspaceMemberships()->with('workspace')->first()?->workspace?->name
                        ?? $workspaceName;
                }

                app(CreateWorkspace::class)->create($principal, $workspaceName);
            }

            return (string) $principal->getKey();
        });

        $mapping = LegacyIdentityMap::query()->firstOrCreate(
            [
                'source_product' => $product,
                'source_entity' => 'user',
                'source_id' => $principalId,
            ],
            [
                'canonical_entity' => 'user',
                'canonical_id' => (string) $platformUser->getAuthIdentifier(),
                'status' => 'reconciled',
                'batch_key' => 'shared-auth-provisioning',
                'reconciliation_notes' => 'Created through shared Core authentication on first product access.',
                'metadata' => ['provisioned_by' => 'core'],
                'imported_at' => now(),
                'reconciled_at' => now(),
            ],
        );

        abort_unless(
            $mapping->canonical_entity === 'user'
                && (string) $mapping->canonical_id === (string) $platformUser->getAuthIdentifier()
                && $mapping->status === 'reconciled',
            409,
            'The product identity mapping needs explicit reconciliation.',
        );
    }

    private function synchronizeProvisionedAccount(string $product, Model $principal, PlatformUser $platformUser): void
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
            DB::connection($product)->table('users')->where('id', $principal->getKey())->update($updates);
        }
    }
}
