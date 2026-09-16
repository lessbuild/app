<?php

namespace App\Http\Requests;

use App\Models\BackupDestination;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestBackupDestinationRequest extends FormRequest
{
    /**
     * Preserve manager and backup-entitlement checks before selecting a workspace-owned test website.
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

    /**
     * Require a website from the same workspace to provide the managed server connection for the test.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->current_organization_id;

        return [
            'website_id' => ['required', Rule::exists('websites', 'id')->where('organization_id', $organizationId)],
        ];
    }
}
