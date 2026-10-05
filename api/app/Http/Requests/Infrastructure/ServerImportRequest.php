<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Enums\ServerType;
use App\Http\Controllers\Infrastructure\CreateServerImportController;
use App\Services\Infrastructure\ServerKeys;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Authorisation happens in InspectServerImport. */
final class ServerImportRequest extends FormRequest
{
    /**
     * Get the validation rules for an existing server to inspect: its name, type, address, SSH port and an unencrypted
     * private key, pasted or the one made for this import.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:31', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
            'type' => ['required', Rule::enum(ServerType::class)],
            'public_ip' => ['required', 'ip'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'key_source' => ['nullable', Rule::in(['ours', 'pasted'])],
            'ssh_private_key' => ['exclude_if:key_source,ours', 'required', 'string', 'max:16384', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! app(ServerKeys::class)->isPrivateKey(trim($value))) {
                    $fail(__('Paste an unencrypted SSH private key.'));
                }
            }],
        ];
    }

    /**
     * Get the validated details with the port as an integer. With the key made for this import, its private half comes
     * from the session; once the server's inspected it isn't needed there any more.
     *
     * @return array{name: string, type: string, public_ip: string, ssh_port: int, ssh_private_key: string}
     */
    public function import(): array
    {
        /** @var array{name: string, type: string, public_ip: string, ssh_port: int|string, ssh_private_key?: string, key_source?: string|null} $data */
        $data = $this->validated();

        if (($data['key_source'] ?? null) === 'ours') {
            $stored = $this->session()->pull(CreateServerImportController::SESSION_KEY);
            $data['ssh_private_key'] = is_string($stored) ? rescue(fn (): string => Crypt::decryptString($stored), '', false) : '';
            if ($data['ssh_private_key'] === '') {
                throw ValidationException::withMessages(['key_source' => __('That key has expired. Reload the page, add the new key and try again.')]);
            }
        }

        return ['name' => $data['name'], 'type' => $data['type'], 'public_ip' => $data['public_ip'], 'ssh_port' => (int) $data['ssh_port'], 'ssh_private_key' => $data['ssh_private_key'] ?? ''];
    }
}
