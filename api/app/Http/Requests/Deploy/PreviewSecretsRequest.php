<?php

declare(strict_types=1);

namespace App\Http\Requests\Deploy;

use Illuminate\Foundation\Http\FormRequest;

/** Secrets chosen for a preview, with the revision the approver reviewed. */
final class PreviewSecretsRequest extends FormRequest
{
    /**
     * Get the validation rules: the reviewed revision and one to fifty variable keys.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'regex:/\A[0-9a-f]{40,64}\z/'],
            'secret_keys' => ['required', 'array', 'min:1', 'max:50'],
            'secret_keys.*' => ['required', 'string', 'max:255', 'regex:/\A[A-Z_][A-Z0-9_]*\z/'],
        ];
    }

    /**
     * Get the revision the approver reviewed.
     *
     * @return string
     */
    public function revision(): string
    {
        return $this->string('revision')->toString();
    }

    /**
     * Get the chosen variable keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_map('strval', (array) $this->input('secret_keys', [])));
    }
}
