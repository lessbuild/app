<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ArchiveMonitorRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $workspace): bool
    {
        $monitor = $this->route('monitor');
        abort_unless($monitor instanceof Monitor && Monitor::forWorkspace($workspace->get())->visibleTo($this->user(), $workspace->get())->whereKey($monitor->id)->exists(), 404);
        Gate::authorize('delete', $monitor);

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0']];
    }
}
