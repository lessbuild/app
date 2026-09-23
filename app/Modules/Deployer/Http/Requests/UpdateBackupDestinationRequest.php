<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Services\BackupDestinationCatalog;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBackupDestinationRequest extends FormRequest
{
    /**
     * Preserve manager and backup-entitlement checks before destination validation.
     */
    public function authorize(): bool
    {
        $destination = $this->route('destination');
        $user = $this->user();
        if (! $destination instanceof BackupDestination
            || $user === null
            || ! $user->can('update', $destination)
            || $user->currentOrganization === null) {
            return false;
        }

        app(Entitlements::class)->enforce($destination->organization, 'backups');

        return true;
    }

    /**
     * Validate an editable S3-compatible destination while allowing blank credentials to retain their encrypted values.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'storage_provider' => ['required', Rule::in(app(BackupDestinationCatalog::class)->keys())],
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'url:https', 'max:255'],
            'bucket' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/i'],
            'region' => ['required', 'string', 'max:100'],
            'access_key' => ['nullable', 'string', 'max:1000'],
            'secret_key' => ['nullable', 'string', 'max:1000'],
            'path_prefix' => ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9._\/\-]+\z/'],
        ];
    }

    /** Apply provider defaults before endpoint validation. */
    protected function prepareForValidation(): void
    {
        $this->merge(app(BackupDestinationCatalog::class)->prepare($this->all()));
    }
}
