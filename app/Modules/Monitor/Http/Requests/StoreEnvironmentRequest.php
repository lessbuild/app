<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('environment') ?? $this->route('application'));

        return true;
    }

    public function rules(): array
    {
        $environment = $this->route('environment');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique(Environment::class, 'slug')->where('application_id', $this->route('application')->id)
                    ->ignore($environment?->id),
            ],
            'status' => ['required', Rule::in(['active', 'paused'])],
        ];
    }
}
