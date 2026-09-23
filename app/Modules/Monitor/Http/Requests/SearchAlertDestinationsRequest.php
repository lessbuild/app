<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchAlertDestinationsRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        if ($destination = $this->route('alertDestination')) {
            abort_unless($destination instanceof AlertDestination && AlertDestination::withTrashed()->forWorkspace($workspace)->whereKey($destination->id)->exists(), 404);
            Gate::authorize($this->routeIs('monitor.alert-destinations.edit') ? 'update' : 'view', $destination);
        } else {
            Gate::authorize('create', [AlertDestination::class, $workspace]);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in(['all', 'enabled', 'paused', 'archived'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'deliveries_page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }
}
