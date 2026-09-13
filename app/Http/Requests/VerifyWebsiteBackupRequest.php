<?php

namespace App\Http\Requests;

use App\Models\WebsiteBackup;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyWebsiteBackupRequest extends FormRequest
{
    /**
     * Preserve authorization and entitlement ordering before validating the confirmation field.
     */
    public function authorize(): bool
    {
        $backup = $this->route('backup');
        $user = $this->user();
        if (! $backup instanceof WebsiteBackup || $user === null) {
            return false;
        }

        $backup->loadMissing('website.organization');
        if (! $user->can('verify', $backup)) {
            return false;
        }

        app(Entitlements::class)->enforce($backup->website->organization, 'backups');

        return true;
    }

    /**
     * Require the exact website name before an isolated verification can be queued.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var WebsiteBackup $backup */
        $backup = $this->route('backup');

        return [
            'confirmation' => ['required', Rule::in([$backup->website->name])],
        ];
    }
}
