<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\EnvironmentVariable;
use App\Models\Preview;
use App\Models\PreviewSecretApproval;
use App\Models\User;
use App\Services\Deploy\PreviewConfiguration;
use App\Services\Deploy\Previews;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApprovePreviewSecrets
{
    /**
     * Create a new ApprovePreviewSecrets instance.
     *
     * Lets a preview's revision use chosen secrets.
     *
     * @param  Previews  $previews  Redeploys the preview with the approved secrets.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Approve runtime secrets from the preview's source environment for the revision the approver reviewed, which must
     * still be the preview's current one. Only the variables' IDs and versions are stored. The preview then deploys
     * again with them; a new revision or a changed secret needs a new approval.
     *
     * @param  User  $actor
     * @param  Preview  $preview
     * @param  string  $revision  the revision the approver reviewed
     * @param  list<string>  $keys
     * @return PreviewSecretApproval
     */
    public function handle(User $actor, Preview $preview, string $revision, array $keys): PreviewSecretApproval
    {
        Gate::forUser($actor)->authorize('approveSecrets', $preview);
        $approval = DB::transaction(function () use ($actor, $preview, $revision, $keys): PreviewSecretApproval {
            $locked = Preview::query()->lockForUpdate()->findOrFail($preview->id);
            StateConflict::unless($locked->isOpen() && hash_equals($locked->revision, $revision), __('The preview has moved on to another revision, or closed. Review it again.'));
            StateConflict::unless($locked->source_environment_id !== null, __('The preview has no source environment to take secrets from.'));
            $keys = array_values(array_unique($keys));
            $variables = EnvironmentVariable::query()->where('environment_id', $locked->source_environment_id)->whereIn('key', $keys)->lockForUpdate()->get();
            if ($variables->count() !== count($keys) || $variables->contains(fn (EnvironmentVariable $variable): bool => ! PreviewConfiguration::isApprovable($variable))) {
                throw ValidationException::withMessages(['secret_keys' => __('Only runtime secrets of the source environment can be approved; the preview’s own key, URL and database can’t be replaced.')]);
            }

            $approval = $locked->secretApprovals()->where('revision', $locked->revision)->first() ?? new PreviewSecretApproval;
            $approval->forceFill([
                'preview_id' => $locked->id, 'revision' => $locked->revision, 'source_environment_id' => $locked->source_environment_id,
                'approved_by' => $actor->id, 'approved_at' => now(), 'revoked_at' => null,
                'variable_versions' => $variables->mapWithKeys(fn (EnvironmentVariable $variable): array => [(string) $variable->id => $variable->current_version])->all(),
            ])->save();

            return $approval;
        });
        $this->previews->reconfigure($preview->refresh());

        return $approval;
    }
}
