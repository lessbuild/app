<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SearchAlertDeliveriesRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $delivery = $this->route('alertDelivery');
        abort_unless($delivery instanceof AlertDelivery && $delivery->workspace_id === $currentWorkspace->get()->id, 404);
        Gate::authorize('view', $delivery);

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
        return ['page' => ['nullable', 'integer', 'between:1,100000']];
    }
}
