<?php

namespace App\Core\Http\Requests;

use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

final class PlatformPasskeyRegistrationRequest extends PasskeyRegistrationRequest
{
    protected $errorBag = 'passkeys';
}
