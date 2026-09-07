<?php

namespace App\Http\Requests;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Support\PublicIpAddress;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use phpseclib4\Crypt\PublicKeyLoader;

class ImportServerRequest extends FormRequest
{
    /**
     * Allow server import submissions only when the user has deployment permission in the current workspace.
     */
    public function authorize(): bool
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(ServerTypeEnum::class)],
            'public_ip' => [
                'required', 'ip',
                Rule::unique('servers', 'public_ip')->where(fn (Builder $query): Builder => $query->where('organization_id', $this->user()->current_organization_id)),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! config('lessbuild.allow_private_server_ips') && ! PublicIpAddress::isValid((string) $value)) {
                        $fail(__('Enter a publicly routable IP address. Private, loopback, link-local, multicast, and reserved addresses are blocked.'));
                    }
                },
            ],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'ssh_private_key' => [
                'required',
                'string',
                'max:20000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $key = trim((string) $value);
                    try {
                        $privateKey = PublicKeyLoader::loadPrivateKey($key);
                    } catch (\Throwable) {
                        $fail(__('Enter an unencrypted OpenSSH-compatible private key.'));

                        return;
                    }
                    if (! method_exists($privateKey, 'getPublicKey')) {
                        $fail(__('Enter an unencrypted OpenSSH-compatible private key.'));
                    }
                },
            ],
        ];
    }
}
