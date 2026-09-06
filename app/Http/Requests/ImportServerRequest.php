<?php

namespace App\Http\Requests;

use App\Models\Enums\Server\ServerTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use phpseclib3\Crypt\PublicKeyLoader;
use Illuminate\Validation\Rule;

class ImportServerRequest extends FormRequest
{
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
                Rule::unique('servers', 'public_ip')->where(fn ($query) => $query->where('organization_id', $this->user()->current_organization_id)),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $isPublic = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                    $isMulticast = str_starts_with(strtolower((string) $value), 'ff')
                        || (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) explode('.', (string) $value)[0] >= 224);
                    if (! config('lessbuild.allow_private_server_ips') && (! $isPublic || $isMulticast)) {
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
                    if (! method_exists($privateKey, 'getPublicKey')) $fail(__('Enter an unencrypted OpenSSH-compatible private key.'));
                },
            ],
        ];
    }
}
