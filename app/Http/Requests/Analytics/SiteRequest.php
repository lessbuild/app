<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Data\Analytics\SiteDetails;
use Illuminate\Foundation\Http\FormRequest;

final class SiteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'domains' => ['required', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            'excluded_paths' => ['nullable', 'string', 'max:2000'],
            'environment_id' => ['nullable', 'string'],
        ];
    }

    public function toDetails(): SiteDetails
    {
        return new SiteDetails(
            name: $this->string('name')->toString(),
            domains: self::lines($this->string('domains')->toString()),
            timezone: $this->string('timezone')->toString(),
            excludedPaths: self::lines($this->string('excluded_paths')->toString()),
            environmentId: $this->filled('environment_id') ? $this->string('environment_id')->toString() : null,
        );
    }

    /** @return list<string> one per line or comma */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[\r\n,]+/', $value) ?: [])));
    }
}
