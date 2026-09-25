<?php

namespace App\Core\Http\Requests;

final class DeletePlatformPasskeyRequest extends PlatformPasskeyActionRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->reauthenticationRules();
    }
}
