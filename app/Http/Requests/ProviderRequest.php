<?php

namespace App\Http\Requests;

use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;

class ProviderRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'description' => 'required',
            'provider' => 'required|string|max:255|in:'.implode(',', [
                Provider::TYPE_GITHUB,
                Provider::TYPE_DIGITALOCEAN,
            ]),
            'token' => [$this->isMethod('post') ? 'required' : 'nullable', 'string'],
        ];
    }
}
