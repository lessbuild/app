<?php

namespace App\Http\Requests;

use App\Models\Build;
use Illuminate\Foundation\Http\FormRequest;

class BuildApprovalRequest extends FormRequest
{
    /** Keep approval validation behind the existing build approval policy and in its named session bag. */
    protected $errorBag = 'approval';

    /**
     * Require the bound build's approval ability before validating optional approval context.
     */
    public function authorize(): bool
    {
        $build = $this->route('build');

        return $build instanceof Build
            && ($this->user()?->can('approve', $build) ?? false);
    }

    /**
     * Validate the optional approval or rejection note.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'approval_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Return the explicitly validated approval note for the approval operation.
     */
    public function approvalNote(): ?string
    {
        return $this->validated('approval_note');
    }
}
