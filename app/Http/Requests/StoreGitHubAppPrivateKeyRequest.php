<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreGitHubAppPrivateKeyRequest extends FormRequest
{
    /** Never flash the uploaded private key back to the session on validation failure. */
    protected $dontFlash = ['private_key'];

    /** Restrict this one-time setup boundary to local platform administration. */
    public function authorize(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('github-app.setup_enabled')
            && ($this->user()?->can('platform-admin') ?? false);
    }

    /**
     * Validate only the upload envelope here; the action validates the PEM and key type before storage.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'private_key' => ['required', 'file', 'max:64'],
        ];
    }

    /** Return the validated upload without exposing its original filename to application code. */
    public function privateKey(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->validated('private_key');

        return $file;
    }
}
