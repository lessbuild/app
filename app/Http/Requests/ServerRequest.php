<?php

namespace App\Http\Requests;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ServerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'type' => [
                new Enum(ServerTypeEnum::class),
            ],
            'provider_id' => [
                'required',
                'integer',
                Rule::exists('providers', 'id')->where(function ($query) {
                    return $query
                        ->where('organization_id', $this->user()->current_organization_id)
                        ->whereIn('provider', Provider::SERVER_TYPES)
                        ->whereNull('deleted_at');
                }),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'region' => ['required'],
            'image' => ['required'],
            'size' => ['required'],
            'recipes' => ['nullable', 'array', 'max:20'],
            'recipes.*' => [
                'integer',
                'distinct',
                Rule::exists('recipes', 'id')->where(function ($query) {
                    return $query->where('organization_id', $this->user()->current_organization_id);
                }),
            ],
        ];
    }
}
