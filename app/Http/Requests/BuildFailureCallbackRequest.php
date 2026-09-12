<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuildFailureCallbackRequest extends FormRequest
{
    /** Signed callback middleware, rather than a user policy, authorizes this machine-to-machine request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the bounded failure details reported by the deployment process.
     *
     * @return array{exit_code: list<string>, message: list<string>}
     */
    public function rules(): array
    {
        return [
            'exit_code' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    /** Return the validated remote exit code, when supplied. */
    public function exitCode(): ?int
    {
        $exitCode = $this->validated('exit_code');

        return $exitCode === null ? null : (int) $exitCode;
    }

    /** Return the validated remote failure message. */
    public function message(): string
    {
        return (string) $this->validated('message');
    }
}
