<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use JsonException;

class StoreApplicationConfigurationRequest extends FormRequest
{
    /**
     * Allow configuration review submissions only for workspace managers of the bound project.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('manageConfiguration', $project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'max:50000'],
            'bindings' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * Return the validated YAML document explicitly rather than passing request input to the review service.
     */
    public function document(): string
    {
        return (string) $this->validated('document');
    }

    /**
     * Return the bounded JSON binding object decoded after request validation.
     *
     * @return array<string, mixed>
     */
    public function bindings(): array
    {
        return $this->parsedBindings;
    }

    /**
     * Decode the web form's JSON bindings with the same depth and object checks as the previous controller boundary.
     */
    protected function passedValidation(): void
    {
        try {
            $bindings = json_decode((string) $this->validated('bindings'), true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidBindings('Bindings must be a valid JSON object.');
        }

        if (! is_array($bindings)) {
            $this->invalidBindings('Bindings must be a JSON object.');
        }

        $this->parsedBindings = $bindings;
    }

    /**
     * Preserve the configuration form's secret-safe failure behavior: validation errors never flash YAML or bindings.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new ValidationException($validator, $this->redirectWithErrors($validator), $this->errorBag);
    }

    /**
     * Return a JSON binding error without allowing the framework to flash the submitted form fields.
     *
     * @return never
     */
    private function invalidBindings(string $message): never
    {
        $validator = ValidatorFactory::make([], []);
        $validator->errors()->add('bindings', $message);

        throw new ValidationException($validator, $this->redirectWithErrors(['bindings' => $message]), $this->errorBag);
    }

    /**
     * Attach errors to the active request session without using the redirector before its session is initialized.
     *
     * @param  Validator|array<string, string>  $errors  Validation messages to flash.
     */
    private function redirectWithErrors(Validator|array $errors): RedirectResponse
    {
        $response = new RedirectResponse($this->getRedirectUrl());
        $response->setSession($this->session());

        return $response->withErrors($errors, $this->errorBag);
    }

    /** @var array<string, mixed> */
    private array $parsedBindings = [];
}
