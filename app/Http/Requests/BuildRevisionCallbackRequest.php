<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuildRevisionCallbackRequest extends FormRequest
{
    /** Signed callback middleware, rather than a user policy, authorizes this machine-to-machine request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the immutable revision attestation and optional commit message.
     *
     * @return array{revision: list<string>, commit_message: list<string>}
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'regex:/\A[0-9a-f]{40,64}\z/i'],
            'commit_message' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Return the validated source revision. */
    public function revision(): string
    {
        return (string) $this->validated('revision');
    }

    /** Return the optional validated commit message. */
    public function commitMessage(): ?string
    {
        $message = $this->validated('commit_message');

        return is_string($message) ? $message : null;
    }
}
