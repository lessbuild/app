<?php

namespace App\Http\Requests;

use App\Models\Enums\Server\ServerTypeEnum;
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
        return true;
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
                    return $query->where('user_id', $this->user()->id);
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
        ];
    }
}
