<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Data\Projects\ProjectDetails;
use Illuminate\Foundation\Http\FormRequest;

final class ProjectRequest extends FormRequest
{
    /**
     * A project's name and optional description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The project's details, with a blank description as none.
     *
     * @return ProjectDetails
     */
    public function toDetails(): ProjectDetails
    {
        return new ProjectDetails($this->string('name')->toString(), $this->filled('description') ? $this->string('description')->toString() : null);
    }
}
