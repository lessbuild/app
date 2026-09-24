<?php

namespace App\Core\Services\Identity;

use App\Core\Enums\ProductKey;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reconcile one explicitly reviewed legacy user mapping without merging accounts by email. */
final class ReconcileLegacyIdentity
{
    /**
     * @return array{status:'ready'|'reconciled'|'already_reconciled'|'blocked',reason?:string}
     */
    public function run(
        string $product,
        string $sourceId,
        string $platformUserId,
        string $reviewer,
        string $evidenceReference,
        bool $apply = false,
    ): array {
        $productKey = ProductKey::tryFrom($product);
        if ($productKey === null) {
            return ['status' => 'blocked', 'reason' => 'unsupported_product'];
        }

        $sourceConnection = config('platform.products.'.$productKey->value.'.database');
        if (! is_string($sourceConnection) || ! Schema::connection($sourceConnection)->hasTable('users')) {
            return ['status' => 'blocked', 'reason' => 'source_user_table_unavailable'];
        }

        if (! Schema::connection($sourceConnection)->hasColumn('users', 'email')
            || ! Schema::connection($sourceConnection)->hasColumn('users', 'email_verified_at')
            || ! Schema::connection('core')->hasTable('users')
            || ! Schema::connection('core')->hasTable('legacy_identity_maps')
            || ! Schema::connection('core')->hasColumn('users', 'email')
            || ! Schema::connection('core')->hasColumn('users', 'email_normalized')
            || ! Schema::connection('core')->hasColumn('users', 'email_verified_at')
            || ! Schema::connection('core')->hasColumn('users', 'status')) {
            return ['status' => 'blocked', 'reason' => 'identity_schema_incomplete'];
        }

        $mapping = LegacyIdentityMap::query()
            ->where('source_product', $productKey->value)
            ->where('source_entity', 'user')
            ->where('source_id', $sourceId)
            ->first();

        if ($mapping === null) {
            return ['status' => 'blocked', 'reason' => 'source_identity_mapping_missing'];
        }

        if ($mapping->status === 'reconciled'
            && $mapping->canonical_entity === 'user'
            && (string) $mapping->canonical_id === $platformUserId) {
            return ['status' => 'already_reconciled'];
        }

        if ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null) {
            return ['status' => 'blocked', 'reason' => 'mapping_not_available_for_review'];
        }

        if ($apply && (! $this->validReviewer($reviewer) || ! $this->validEvidenceReference($evidenceReference))) {
            return ['status' => 'blocked', 'reason' => 'reviewer_and_evidence_reference_required'];
        }

        $sourceUser = $this->sourceUser($sourceConnection, $sourceId);
        $platformUser = PlatformUser::query()
            ->whereKey($platformUserId)
            ->first(['id', 'email', 'email_normalized', 'email_verified_at', 'status']);

        $reason = $this->accountConsistencyFailure($sourceUser, $platformUser);
        if ($reason !== null) {
            return ['status' => 'blocked', 'reason' => $reason];
        }

        $alreadyMapped = LegacyIdentityMap::query()
            ->where('source_product', $productKey->value)
            ->where('source_entity', 'user')
            ->where('canonical_entity', 'user')
            ->where('canonical_id', $platformUserId)
            ->where('status', 'reconciled')
            ->where('source_id', '!=', $sourceId)
            ->exists();

        if ($alreadyMapped) {
            return ['status' => 'blocked', 'reason' => 'platform_user_already_mapped_to_another_source_user'];
        }

        if (! $apply) {
            return ['status' => 'ready'];
        }

        try {
            return DB::connection('core')->transaction(function () use (
                $productKey,
                $sourceConnection,
                $sourceId,
                $platformUserId,
                $reviewer,
                $evidenceReference,
            ): array {
                $platformUser = PlatformUser::query()->whereKey($platformUserId)->lockForUpdate()->first([
                    'id', 'email', 'email_normalized', 'email_verified_at', 'status',
                ]);
                $sourceUser = $this->sourceUser($sourceConnection, $sourceId);
                $reason = $this->accountConsistencyFailure($sourceUser, $platformUser);

                if ($reason !== null) {
                    return ['status' => 'blocked', 'reason' => $reason];
                }

                $mapping = LegacyIdentityMap::query()
                    ->where('source_product', $productKey->value)
                    ->where('source_entity', 'user')
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->first();

                if ($mapping === null) {
                    return ['status' => 'blocked', 'reason' => 'source_identity_mapping_missing'];
                }

                if ($mapping->status === 'reconciled'
                    && $mapping->canonical_entity === 'user'
                    && (string) $mapping->canonical_id === $platformUserId) {
                    return ['status' => 'already_reconciled'];
                }

                if ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null) {
                    return ['status' => 'blocked', 'reason' => 'mapping_not_available_for_review'];
                }

                $alreadyMapped = LegacyIdentityMap::query()
                    ->where('source_product', $productKey->value)
                    ->where('source_entity', 'user')
                    ->where('canonical_entity', 'user')
                    ->where('canonical_id', $platformUserId)
                    ->where('status', 'reconciled')
                    ->where('source_id', '!=', $sourceId)
                    ->exists();

                if ($alreadyMapped) {
                    return ['status' => 'blocked', 'reason' => 'platform_user_already_mapped_to_another_source_user'];
                }

                $now = now();
                $metadata = is_array($mapping->metadata) ? $mapping->metadata : [];
                $reconciliation = [
                    'reviewer' => trim($reviewer),
                    'evidence_reference' => trim($evidenceReference),
                    'previous_batch_key' => $mapping->batch_key,
                    'previous_reconciliation_notes' => $mapping->reconciliation_notes,
                    'verified_email_agreement_checked' => true,
                    'ownership_proof' => 'operator_attested',
                    'reconciled_at' => $now->toIso8601String(),
                ];
                $reconciliationHistory = $metadata['manual_reconciliation_history'] ?? [];
                $reconciliationHistory = is_array($reconciliationHistory) && array_is_list($reconciliationHistory)
                    ? $reconciliationHistory
                    : [];
                $previousReconciliation = $metadata['manual_reconciliation'] ?? null;
                if (is_array($previousReconciliation)
                    && ($reconciliationHistory === [] || $reconciliationHistory[array_key_last($reconciliationHistory)] !== $previousReconciliation)) {
                    $reconciliationHistory[] = $previousReconciliation;
                }
                $reconciliationHistory[] = $reconciliation;
                $metadata['manual_reconciliation'] = $reconciliation;
                $metadata['manual_reconciliation_history'] = $reconciliationHistory;

                $mapping->forceFill([
                    'canonical_entity' => 'user',
                    'canonical_id' => $platformUserId,
                    'status' => 'reconciled',
                    'batch_key' => 'manual-identity-reconciliation-v1',
                    'reconciliation_notes' => 'Manually reconciled after operator ownership review. Verified email agreement was checked for consistency; email agreement alone is not ownership proof.',
                    'metadata' => $metadata,
                    'reconciled_at' => $now,
                ])->save();

                return ['status' => 'reconciled'];
            }, attempts: 3);
        } catch (\Throwable $exception) {
            report($exception);

            return ['status' => 'blocked', 'reason' => 'identity_reconciliation_failed'];
        }
    }

    private function accountConsistencyFailure(?object $sourceUser, ?PlatformUser $platformUser): ?string
    {
        if ($sourceUser === null || $platformUser === null) {
            return 'source_or_platform_user_missing';
        }

        if ($platformUser->status !== 'active') {
            return 'platform_user_not_active';
        }

        if ($sourceUser->email_verified_at === null || $platformUser->email_verified_at === null) {
            return 'both_accounts_must_have_verified_email';
        }

        if (isset($sourceUser->deleted_at)) {
            return 'source_user_is_deleted';
        }

        $sourceEmail = $this->normalizeEmail(is_string($sourceUser->email) ? $sourceUser->email : null);
        $platformEmail = $this->normalizeEmail(is_string($platformUser->email) ? $platformUser->email : null);
        if ($sourceEmail === null || $platformEmail === null || ! hash_equals($sourceEmail, $platformEmail)) {
            return 'verified_emails_do_not_match';
        }

        $normalizedPlatformEmail = $this->normalizeEmail(is_string($platformUser->email_normalized)
            ? $platformUser->email_normalized
            : null);
        if ($normalizedPlatformEmail === null || ! hash_equals($platformEmail, $normalizedPlatformEmail)) {
            return 'platform_email_normalization_is_inconsistent';
        }

        return null;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private function sourceUser(string $connection, string $sourceId): ?object
    {
        $columns = ['id', 'email', 'email_verified_at'];
        if (Schema::connection($connection)->hasColumn('users', 'deleted_at')) {
            $columns[] = 'deleted_at';
        }

        return DB::connection($connection)->table('users')
            ->where('id', $sourceId)
            ->first($columns);
    }

    private function validReviewer(string $reviewer): bool
    {
        $reviewer = trim($reviewer);

        return $reviewer !== ''
            && mb_strlen($reviewer) <= 160
            && preg_match('/[\x00-\x1F\x7F]/u', $reviewer) === 0;
    }

    private function validEvidenceReference(string $evidenceReference): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{2,159}\z/', trim($evidenceReference)) === 1;
    }
}
