<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Enums\ServerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation and the provider's account happen in CreateServer. */
final class ServerRequest extends FormRequest
{
    /**
     * Get the validation rules: a new server's provider, type, name (a valid hostname label), the provider's region,
     * size and image, and up to 20 recipes to run.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(ServerType::class)],
            'database_engine' => ['nullable', 'in:mysql,postgres'],
            'name' => ['required', 'string', 'max:31', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
            'region' => ['required', 'string', 'max:100'],
            'size' => ['required', 'string', 'max:100'],
            'image' => ['required', 'string', 'max:100'],
            'recipe_ids' => ['sometimes', 'array', 'max:20'],
            'recipe_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Get the validated details with the provider ID as an integer and the recipes, in the order chosen.
     *
     * @return array{provider_id: int, type: string, name: string, region: string, size: string, image: string, recipe_ids: list<int>, database_engine?: string|null}
     */
    public function serverDetails(): array
    {
        /** @var array{provider_id: int|string, type: string, name: string, region: string, size: string, image: string, database_engine?: string|null} $data */
        $data = $this->safe()->except('recipe_ids');

        return [...$data, 'provider_id' => (int) $data['provider_id'], 'recipe_ids' => array_values(array_map('intval', (array) $this->validated('recipe_ids', [])))];
    }
}
