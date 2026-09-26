<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;

final class PlatformSocialLoginResolution
{
    public const RESOLVED = 'resolved';

    public const EMAIL_EXISTS = 'email_exists';

    public const REGISTRATION_CLOSED = 'registration_closed';

    public const IDENTITY_DISABLED = 'identity_disabled';

    public const INVITATION_INVALID = 'invitation_invalid';

    public const INVITATION_EMAIL_MISMATCH = 'invitation_email_mismatch';

    public function __construct(
        public readonly string $status,
        public readonly ?PlatformUser $user = null,
    ) {}
}
