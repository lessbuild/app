<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class ConfigurationOperationRequest extends FormRequest
{
    /**
     * These API operations accept no replacement document or operation payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Return the exact operation-identity error used by the existing JSON contract.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->all() === []) {
                return;
            }

            [$key, $message] = $this->unexpectedInput();
            $validator->errors()->add($key, $message);
        }];
    }

    /** @return array{0: string, 1: string} */
    abstract protected function unexpectedInput(): array;
}
