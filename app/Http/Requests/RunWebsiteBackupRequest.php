<?php

namespace App\Http\Requests;

use App\Models\BackupDestination;
use App\Models\Website;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunWebsiteBackupRequest extends FormRequest
{
    /**
     * Preserve the website management and backup-entitlement checks before destination validation.
     */
    public function authorize(): bool
    {
        $website = $this->route('website');
        $user = $this->user();
        if (! $website instanceof Website || $user === null || ! $user->can('backup', $website)) {
            return false;
        }

        app(Entitlements::class)->enforce($website->organization, 'backups');

        return true;
    }

    /**
     * Validate a destination belonging to the website's workspace.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Website $website */
        $website = $this->route('website');

        return [
            'backup_destination_id' => [
                'required',
                Rule::exists(BackupDestination::class, 'id')->where('organization_id', $website->organization_id),
            ],
        ];
    }
}
