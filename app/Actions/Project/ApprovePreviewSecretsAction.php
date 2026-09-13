<?php

namespace App\Actions\Project;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\PreviewDeployment;
use App\Models\PreviewSecretApproval;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovePreviewSecretsAction
{
    /**
     * Record a revision- and variable-version-bound preview secret approval without storing values.
     *
     * @param  PreviewDeployment  $preview  Preview whose current revision may receive an explicit scope.
     * @param  User  $approver  Manager whose current workspace access is rechecked in the transaction.
     * @param  string  $revision  Revision the manager explicitly reviewed, which must still be current.
     * @param  list<string>  $secretKeys  Validated variable names selected by the manager.
     * @return PreviewSecretApproval|null Null when the preview was closed or access changed concurrently.
     *
     * @throws ValidationException When selected variables are not eligible for preview use.
     */
    public function handle(PreviewDeployment $preview, User $approver, string $revision, array $secretKeys): ?PreviewSecretApproval
    {
        return DB::transaction(function () use ($preview, $approver, $revision, $secretKeys): ?PreviewSecretApproval {
            $locked = PreviewDeployment::query()->whereKey($preview->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === PreviewDeployment::STATUS_CLOSED || $locked->closed_at !== null
                || ! hash_equals((string) $locked->revision, $revision)) {
                return null;
            }

            $project = Project::query()->with('organization')->lockForUpdate()->find($locked->project_id);
            if (! $project || (int) $project->organization_id !== (int) $approver->current_organization_id
                || ! $project->organization->permits($approver, 'manage')) {
                return null;
            }

            $sourceWebsiteId = $locked->sourceRepository()->value('website_id');
            if ($sourceWebsiteId === null || $locked->source_environment_id === null || ! $locked->revision) {
                return null;
            }

            $environment = Environment::query()
                ->whereKey($locked->source_environment_id)
                ->where('project_id', $locked->project_id)
                ->where('website_id', $sourceWebsiteId)
                ->first();
            if (! $environment) {
                return null;
            }

            $keys = array_values(array_unique($secretKeys));
            $variables = EnvironmentVariable::query()
                ->where('environment_id', $environment->id)
                ->whereIn('key', $keys)
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            if (count($keys) !== $variables->count()
                || $variables->contains(fn (EnvironmentVariable $variable): bool => ! $variable->is_secret
                    || ! in_array($variable->scope, ['runtime', 'all'], true)
                    || in_array($variable->key, PreviewSecretApproval::PROTECTED_KEYS, true))) {
                $exception = ValidationException::withMessages([
                    'secret_keys' => __('Only existing runtime secrets may be approved for a preview; preview-owned credentials are never replaceable.'),
                ]);
                $exception->errorBag = 'preview_secrets';

                throw $exception;
            }

            return $locked->secretApprovals()->updateOrCreate(
                ['revision' => $locked->revision],
                [
                    'source_environment_id' => $environment->id,
                    'approved_by' => $approver->id,
                    'variable_versions' => $variables->mapWithKeys(
                        fn (EnvironmentVariable $variable): array => [(string) $variable->id => $variable->current_version],
                    )->all(),
                    'approved_at' => now(),
                    'revoked_at' => null,
                ],
            );
        });
    }
}
