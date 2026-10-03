<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Rules\Hostname;
use App\Support\Hostname as HostnameInput;
use Illuminate\Foundation\Http\FormRequest;

final class LoadBalancerRequest extends FormRequest
{
    /**
     * Get the validation rules: a load balancer's hostname, health check path, proxy server (only when creating) and
     * website.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            'health_path' => ['required', 'string', 'max:255', 'regex:#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#'],
            'server_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer'],
            'website_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Get the validated load balancer.
     *
     * @return array{hostname: string, health_path: string, server_id?: int|string, website_id?: int|string|null}
     */
    public function loadBalancer(): array
    {
        /** @var array{hostname: string, health_path: string, server_id?: int|string, website_id?: int|string|null} */
        return $this->validated();
    }

    /**
     * Clean the typed hostname before validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['hostname' => HostnameInput::fromInput($this->input('hostname'))]);
    }
}
