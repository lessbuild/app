<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use Illuminate\Foundation\Http\FormRequest;

final class LoadBalancerNodeRequest extends FormRequest
{
    /**
     * Get the validation rules: a node's server (only when adding one), upstream port, weight and whether it receives
     * traffic.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'server_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer'],
            'upstream_port' => ['required', 'integer', 'between:1,65535'],
            'weight' => ['required', 'integer', 'between:1,10'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the validated node.
     *
     * @return array{server_id?: int|string, upstream_port: int|string, weight: int|string, is_enabled?: bool|string|null}
     */
    public function node(): array
    {
        /** @var array{server_id?: int|string, upstream_port: int|string, weight: int|string, is_enabled?: bool|string|null} */
        return $this->validated();
    }
}
