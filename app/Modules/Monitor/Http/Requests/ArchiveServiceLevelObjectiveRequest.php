<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ArchiveServiceLevelObjectiveRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        $objective = $this->route('serviceLevelObjective');
        abort_unless($objective instanceof ServiceLevelObjective && ServiceLevelObjective::forWorkspace($workspace)->visibleTo($this->user(), $workspace)->whereKey($objective->id)->exists(), 404);
        Gate::authorize('update', $workspace);

        return true;
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }
}
