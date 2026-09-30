<?php

declare(strict_types=1);

namespace App\Http\Requests\Deploy;

use Illuminate\Foundation\Http\FormRequest;

/** A repository's preview settings: on or off, the wildcard domain, the lifetime and the initialisation command. */
final class PreviewSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules: a domain name (needed when previews are on), a lifetime of 1 to 720 hours, and a
     * command of at most 2,000 characters.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'previews_enabled' => ['required', 'boolean'],
            'preview_domain' => ['required_if:previews_enabled,true', 'nullable', 'string', 'max:200', 'regex:/\A([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/'],
            'preview_ttl_hours' => ['required', 'integer', 'between:1,720'],
            'preview_initialization_command' => ['nullable', 'string', 'max:2000'],
            'preview_database_source_website_id' => ['nullable', 'integer'],
            'preview_database_mode' => ['nullable', 'in:full,sample,schema'],
        ];
    }

    /**
     * Get the validation messages that need more than the defaults.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['preview_domain.regex' => __('Enter a domain like preview.example.com, whose wildcard DNS points at the server.')];
    }

    /**
     * Get the settings as repository columns.
     *
     * @return array{previews_enabled: bool, preview_domain: string|null, preview_ttl_hours: int, preview_initialization_command: string|null, preview_database_source_website_id: int|null, preview_database_mode: string}
     */
    public function settings(): array
    {
        $command = trim($this->string('preview_initialization_command')->toString());

        return [
            'previews_enabled' => $this->boolean('previews_enabled'),
            'preview_domain' => $this->filled('preview_domain') ? $this->string('preview_domain')->toString() : null,
            'preview_ttl_hours' => $this->integer('preview_ttl_hours'),
            'preview_initialization_command' => $command === '' ? null : $command,
            'preview_database_source_website_id' => $this->filled('preview_database_source_website_id') ? $this->integer('preview_database_source_website_id') : null,
            'preview_database_mode' => in_array($this->input('preview_database_mode'), ['sample', 'schema'], true) ? (string) $this->input('preview_database_mode') : 'full',
        ];
    }

    /**
     * Normalise before validating: the checkbox becomes a boolean, and the domain loses any scheme, `*.` prefix,
     * trailing slash and capitals.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $domain = strtolower(trim($this->string('preview_domain')->toString()));
        $domain = rtrim((string) preg_replace(['#\Ahttps?://#', '#\A\*\.#'], '', $domain), '/');
        $this->merge(['previews_enabled' => $this->boolean('previews_enabled'), 'preview_domain' => $domain === '' ? null : $domain]);
    }
}
