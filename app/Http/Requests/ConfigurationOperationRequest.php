<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

abstract class ConfigurationOperationRequest extends FormRequest
{
    /**
     * These operations accept only their route-bound identity and CSRF token.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Reject replacement input after route and policy checks have preserved the existing 404 ordering.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->except('_token') === []) {
                return;
            }

            [$key, $message] = $this->unexpectedInput();
            $validator->errors()->add($key, $message);
        }];
    }

    /**
     * Preserve configuration's existing no-`_old_input` behavior for unexpected operation input.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new ValidationException($validator, $this->redirectWithErrors($validator), $this->errorBag);
    }

    /**
     * Check the manager-only project capability used by every web configuration operation.
     */
    protected function managerCanConfigure(Project $project): bool
    {
        return $this->user()?->can('manageConfiguration', $project) ?? false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    abstract protected function unexpectedInput(): array;

    /**
     * @param  Validator|array<string, string>  $errors  Validation messages to flash.
     */
    private function redirectWithErrors(Validator|array $errors): RedirectResponse
    {
        $response = new RedirectResponse($this->getRedirectUrl());
        $response->setSession($this->session());

        return $response->withErrors($errors, $this->errorBag);
    }
}
