<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchReleasesRequest extends FormRequest
{
    public const RANGES = ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];

    public const WINDOWS = [15 => '15 minutes', 60 => '1 hour', 360 => '6 hours', 1440 => '24 hours'];

    public function authorize(CurrentWorkspace $workspace): bool
    {
        if ($release = $this->route('release')) {
            abort_unless($release instanceof Release && Release::forWorkspace($workspace->get())->whereKey($release->id)->exists(), 404);
            Gate::authorize('view', $release);
        }
        if ($deployment = $this->route('deployment')) {
            abort_unless($deployment instanceof Deployment && Deployment::forWorkspace($workspace->get())->whereKey($deployment->id)->exists(), 404);
            Gate::authorize('view', $deployment);
        }
        if ($environment = $this->route('environment')) {
            abort_unless($environment instanceof Environment && Environment::forWorkspace($workspace->get())->whereKey($environment->id)->exists(), 404);
            Gate::authorize('view', $environment);
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
            'q' => ['nullable', 'string', 'max:255'],
            'application' => ['nullable', 'integer', 'min:1'],
            'environment' => ['nullable', 'integer', 'min:1'],
            'baseline' => ['nullable', 'integer', 'min:1'],
            'range' => ['nullable', 'string', Rule::in(array_keys(self::RANGES))],
            'window' => ['nullable', 'integer', Rule::in(array_keys(self::WINDOWS))],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'events_page' => ['nullable', 'integer', 'between:1,100000'],
            'issues_page' => ['nullable', 'integer', 'between:1,100000'],
            'deployments_page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_replace(['range' => '24h', 'window' => 60], array_filter($this->validated(), fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
