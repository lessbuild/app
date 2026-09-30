<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Data\Analytics\SiteDetails;
use App\Support\IpRanges;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class SiteRequest extends FormRequest
{
    /**
     * Get the validation rules: a site's name, domains and excluded paths (one per line or comma-separated), timezone
     * and environment.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'domains' => ['required', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            'excluded_paths' => ['nullable', 'string', 'max:2000'],
            'custom_properties' => ['nullable', 'string', 'max:500', 'regex:/^[a-z0-9_,\s]*$/'],
            'excluded_ips' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (self::lines((string) $value) as $range) {
                    if (! IpRanges::valid($range)) {
                        $fail(__(':range isn’t an address or network.', ['range' => $range]));
                    }
                }
            }],
            'environment_id' => ['nullable', 'string'],
        ];
    }

    /**
     * Build the site's settings with domains, excluded paths and custom property keys as lists.
     *
     * @return SiteDetails
     */
    public function toDetails(): SiteDetails
    {
        return new SiteDetails(
            name: $this->string('name')->toString(),
            domains: self::lines($this->string('domains')->toString()),
            timezone: $this->string('timezone')->toString(),
            excludedPaths: self::lines($this->string('excluded_paths')->toString()),
            environmentId: $this->filled('environment_id') ? $this->string('environment_id')->toString() : null,
            excludedIps: array_slice(self::lines($this->string('excluded_ips')->toString()), 0, 50),
            customProperties: array_slice(array_values(array_unique(array_filter(self::lines($this->string('custom_properties')->toString()), fn (string $key): bool => preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) === 1))), 0, 10),
        );
    }

    /**
     * Split text on new lines and commas, dropping blanks.
     *
     * @param  string  $value
     * @return list<string> one per line or comma
     */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[\r\n,]+/', $value) ?: [])));
    }
}
