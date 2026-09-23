<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchIncidentsRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        if ($incident = $this->route('incident')) {
            abort_unless($incident instanceof Incident && Incident::forWorkspace($currentWorkspace->get())->whereKey($incident->id)->exists(), 404);
            Gate::authorize('view', $incident);
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
            'status' => ['nullable', 'string', Rule::in(['active', 'all', 'open', 'acknowledged', 'resolved'])],
            'rule' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }
}
