<?php

namespace App\Http\Requests;

use App\Models\BackupDestination;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;

class StoreBackupDestinationRequest extends FormRequest
{
    /**
     * Preserve manager and backup-entitlement checks before destination validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', BackupDestination::class) || $user->currentOrganization === null) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'backups');

        return true;
    }

    /**
     * Validate the encrypted S3-compatible destination connection.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'url:https', 'max:255'],
            'bucket' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/i'],
            'region' => ['required', 'string', 'max:100'],
            'access_key' => ['required', 'string', 'max:1000'],
            'secret_key' => ['required', 'string', 'max:1000'],
            'path_prefix' => ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9._\/\-]+\z/'],
        ];
    }
}
