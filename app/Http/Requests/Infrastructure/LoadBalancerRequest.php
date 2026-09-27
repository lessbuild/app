<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Rules\Hostname;
use Illuminate\Foundation\Http\FormRequest;

final class LoadBalancerRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            'health_path' => ['required', 'string', 'max:255', 'regex:#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#'],
            'server_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer'],
            'website_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array{hostname: string, health_path: string, server_id?: int|string, website_id?: int|string|null} */
    public function loadBalancer(): array
    {
        /** @var array{hostname: string, health_path: string, server_id?: int|string, website_id?: int|string|null} */
        return $this->validated();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['hostname' => strtolower(rtrim(preg_replace('#^https?://#i', '', trim((string) $this->input('hostname'))) ?? '', '/'))]);
    }
}
