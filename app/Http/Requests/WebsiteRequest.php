<?php

namespace App\Http\Requests;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'server_id' => [
                'required',
                'integer',
                Rule::exists('servers', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->where('provisioning_status', Server::STATUS_ACTIVE)
                    ->whereIn('type', ServerTypeEnum::websiteHostingValues())),
            ],
            'url' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'environment' => [$this->isMethod('post') ? 'required' : 'present', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'server_id.exists' => __('Select an active application or web server.'),
        ];
    }
}
