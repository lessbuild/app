<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Enums\ServerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation and the provider's account happen in CreateServer. */
final class ServerRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(ServerType::class)],
            'name' => ['required', 'string', 'max:31', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
            'region' => ['required', 'string', 'max:100'],
            'size' => ['required', 'string', 'max:100'],
            'image' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array{provider_id: int, type: string, name: string, region: string, size: string, image: string} */
    public function serverDetails(): array
    {
        /** @var array{provider_id: int|string, type: string, name: string, region: string, size: string, image: string} $data */
        $data = $this->validated();

        return [...$data, 'provider_id' => (int) $data['provider_id']];
    }
}
