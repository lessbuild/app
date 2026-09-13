<?php

namespace App\Http\Requests;

use App\Models\PreviewDeployment;
use Illuminate\Foundation\Http\FormRequest;

class ApprovePreviewSecretsRequest extends FormRequest
{
    /** Keep secret-scope validation in a distinct session bag. */
    protected $errorBag = 'preview_secrets';

    /** Never flash the submitted scope back into the session after a sensitive operation fails. */
    protected $dontFlash = ['secret_keys'];

    /**
     * Require a visible preview and workspace management permission before validating its selected keys.
     */
    public function authorize(): bool
    {
        $preview = $this->route('preview');

        return $preview instanceof PreviewDeployment
            && ($this->user()?->can('approveSecrets', $preview) ?? false);
    }

    /**
     * Validate only secret variable names; the action revalidates their source, scope and current versions.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'regex:/\A[0-9a-f]{40,64}\z/D'],
            'secret_keys' => ['required', 'array', 'min:1', 'max:50'],
            'secret_keys.*' => ['required', 'string', 'max:100', 'regex:/\A[A-Z_][A-Z0-9_]*\z/D', 'distinct'],
        ];
    }

    /** @return string Revision the manager explicitly reviewed in the page. */
    public function revision(): string
    {
        return (string) $this->validated('revision');
    }

    /** @return list<string> Explicitly selected secret variable names. */
    public function secretKeys(): array
    {
        /** @var list<string> $keys */
        $keys = $this->validated('secret_keys', []);

        return array_values($keys);
    }
}
