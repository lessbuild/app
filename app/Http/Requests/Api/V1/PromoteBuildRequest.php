<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Build;
use App\Services\ControlPlaneAccess;
use Illuminate\Foundation\Http\FormRequest;

class PromoteBuildRequest extends FormRequest
{
    /**
     * Preserve deploy-token access before build visibility and request validation.
     */
    public function authorize(): bool
    {
        app(ControlPlaneAccess::class)->enforce($this, 'deploy');
        $build = $this->route('build');

        return $build instanceof Build
            && ($this->user()?->can('view', $build) ?? false);
    }

    /**
     * Preserve the API promotion target and optional note contract.
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

    /**
     * Return the validated target identity explicitly for the current-workspace lookup.
     */
    public function targetEnvironmentId(): int
    {
        return (int) $this->validated('target_environment_id');
    }

    /**
     * Return an optional validated note without passing unrestricted request data onward.
     */
    public function promotionNote(): ?string
    {
        $note = $this->validated('promotion_note');

        return is_string($note) ? $note : null;
    }
}
