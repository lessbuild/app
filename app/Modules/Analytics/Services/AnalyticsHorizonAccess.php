<?php

namespace App\Modules\Analytics\Services;

use App\Core\Models\PlatformUser;

final class AnalyticsHorizonAccess
{
    public function allows(mixed $user): bool
    {
        if (! $user instanceof PlatformUser || $user->status !== 'active') {
            return false;
        }

        $email = mb_strtolower(trim((string) $user->email));

        return $email !== '' && in_array($email, config('lessbuild.platform_admin_emails', []), true);
    }
}
