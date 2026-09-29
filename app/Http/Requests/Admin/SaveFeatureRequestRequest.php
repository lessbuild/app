<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FeatureRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveFeatureRequestRequest extends FormRequest
{
    /**
     * Get the rules for a roadmap request: a short public title, an optional description and a status.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(array_keys(FeatureRequest::STATUSES))],
        ];
    }

    /**
     * Get the validated request as the action takes it.
     *
     * @return array{title: string, description: ?string, status: string}
     */
    public function details(): array
    {
        return ['title' => $this->string('title')->trim()->toString(), 'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null, 'status' => $this->string('status')->toString()];
    }
}
