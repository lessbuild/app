<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Enums\ServerType;
use App\Services\Infrastructure\ServerKeys;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation happens in InspectServerImport. */
final class ServerImportRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:31', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
            'type' => ['required', Rule::enum(ServerType::class)],
            'public_ip' => ['required', 'ip'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'ssh_private_key' => ['required', 'string', 'max:16384', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! app(ServerKeys::class)->isPrivateKey(trim($value))) {
                    $fail(__('Paste an unencrypted SSH private key.'));
                }
            }],
        ];
    }

    /** @return array{name: string, type: string, public_ip: string, ssh_port: int, ssh_private_key: string} */
    public function import(): array
    {
        /** @var array{name: string, type: string, public_ip: string, ssh_port: int|string, ssh_private_key: string} $data */
        $data = $this->validated();

        return [...$data, 'ssh_port' => (int) $data['ssh_port']];
    }
}
