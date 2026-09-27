<?php

declare(strict_types=1);

namespace App\Http\Requests\Deploy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * A configuration document with its bindings. The API sends `bindings` as a JSON object; the page sends it as JSON
 * text in a field, which is decoded here.
 */
final class ConfigurationRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['document' => ['required', 'string', 'max:50000'], 'bindings' => ['present']];
    }

    public function document(): string
    {
        return $this->string('document')->toString();
    }

    /** @return array<string, mixed> */
    public function bindings(): array
    {
        $bindings = $this->input('bindings');
        if (is_string($bindings)) {
            $bindings = trim($bindings) === '' ? [] : json_decode($bindings, true);
        }
        if (! is_array($bindings) || (array_is_list($bindings) && $bindings !== [])) {
            throw ValidationException::withMessages(['bindings' => __('Bindings must be a JSON object with placements, secrets and repositories.')]);
        }

        /** @var array<string, mixed> $bindings */
        return $bindings;
    }
}
