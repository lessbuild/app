<?php

namespace App\Http\Requests;

use App\Models\Build;
use Illuminate\Foundation\Http\FormRequest;

class PromoteBuildRequest extends FormRequest
{
    /** Preserve build visibility and workspace deployment authorization before request validation. */
    public function authorize(): bool
    {
        $build = $this->route('build');

        return $build instanceof Build
            && ($this->user()?->can('promote', $build) ?? false);
    }

    /**
     * Validate the workspace target identity and optional promotion note.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'target_environment_id' => ['required', 'integer'],
            'promotion_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Return the validated target identity for the controller's organization-scoped lookup. */
    public function targetEnvironmentId(): int
    {
        return (int) $this->validated('target_environment_id');
    }

    /** Return the optional validated note without passing unrestricted request input onward. */
    public function promotionNote(): ?string
    {
        $note = $this->validated('promotion_note');

        return is_string($note) ? $note : null;
    }
}
