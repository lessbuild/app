<?php

namespace App\Http\Requests;

use App\Models\WebsiteBackupSchedule;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBackupScheduleRequest extends FormRequest
{
    /**
     * Preserve manager and backup-entitlement checks before schedule validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', WebsiteBackupSchedule::class) || $user->currentOrganization === null) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'backups');

        return true;
    }

    /**
     * Validate workspace-owned website and destination IDs with UTC timing and retention.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->current_organization_id;

        return [
            'website_id' => ['required', Rule::exists('websites', 'id')->where('organization_id', $organizationId)],
            'backup_destination_id' => ['required', Rule::exists('backup_destinations', 'id')->where('organization_id', $organizationId)],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'weekday' => ['nullable', 'integer', 'between:0,6', 'required_if:frequency,weekly'],
            'run_at' => ['required', 'date_format:H:i'],
            'retention_count' => ['required', 'integer', 'between:1,365'],
        ];
    }
}
