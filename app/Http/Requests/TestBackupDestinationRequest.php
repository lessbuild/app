<?php

namespace App\Http\Requests;

use App\Models\BackupDestination;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;

class TestBackupDestinationRequest extends FormRequest
{
    /**
     * Preserve manager and backup-entitlement checks before probing the workspace-owned destination.
     */
    public function authorize(): bool
    {
        $destination = $this->route('destination');
        $user = $this->user();
        if (! $destination instanceof BackupDestination
            || $user === null
            || ! $user->can('test', $destination)
            || $user->currentOrganization === null) {
            return false;
        }

        app(Entitlements::class)->enforce($destination->organization, 'backups');

        return true;
    }

    /** No additional request fields are required for a local destination probe. */
    public function rules(): array
    {
        return [];
    }
}
