<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\MaintenanceWindow;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveMaintenanceWindowRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        $window = $this->route('maintenanceWindow');
        abort_unless(! $window instanceof MaintenanceWindow || $window->workspace_id === $workspace->id, 404);
        Gate::authorize('update', $workspace);

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i', 'after:starts_at'],
        ];
    }
}
