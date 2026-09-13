<?php

namespace App\Services;

use App\Models\EnvironmentVariable;
use App\Models\PreviewDeployment;
use App\Models\PreviewSecretApproval;

class PreviewSecretApprovalResolver
{
    /**
     * Resolve an approval only when its revision, source environment and secret versions still match.
     *
     * The approval stores variable identities and versions, never plaintext values. Any mismatch
     * invalidates the complete scope so a rotated or reclassified secret cannot be forwarded partly.
     *
     * @param  PreviewDeployment  $preview  Preview whose approved scope is being read.
     * @param  string  $revision  Exact provider revision about to be executed.
     * @return array<string, string> Approved runtime values, or an empty map when the approval is absent or stale.
     */
    public function valuesFor(PreviewDeployment $preview, string $revision): array
    {
        $approval = $preview->secretApprovals()
            ->where('revision', $revision)
            ->whereNull('revoked_at')
            ->latest('approved_at')
            ->first();

        if (! $approval || ! $approval->source_environment_id
            || (int) $approval->source_environment_id !== (int) $preview->source_environment_id) {
            return [];
        }

        $versions = $approval->variable_versions;
        if (! is_array($versions) || $versions === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): ?int => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
            array_keys($versions),
        )));
        if (count($ids) !== count($versions)) {
            return [];
        }

        $variables = EnvironmentVariable::query()
            ->where('environment_id', $approval->source_environment_id)
            ->whereKey($ids)
            ->where('is_secret', true)
            ->whereIn('scope', ['runtime', 'all'])
            ->get()
            ->keyBy('id');

        if ($variables->count() !== count($ids)) {
            return [];
        }

        $resolved = [];
        foreach ($versions as $id => $version) {
            $variable = $variables->get((int) $id);
            if (! $variable || ! is_numeric($version) || (int) $variable->current_version !== (int) $version
                || in_array($variable->key, PreviewSecretApproval::PROTECTED_KEYS, true)
                || ! is_string($variable->value)) {
                return [];
            }
            $resolved[$variable->key] = $variable->value;
        }

        return $resolved;
    }
}
