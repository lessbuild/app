<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin panel avatars drawn here from the person's initials, so names aren't sent to an outside avatar service.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    /**
     * Get an SVG data URL showing the record's initials.
     *
     * @param  Model  $record
     * @return string
     */
    public function get(Model $record): string
    {
        $name = trim((string) ($record->getAttribute('name') ?? $record->getAttribute('email') ?? '?'));
        $words = preg_split('/\s+/', $name) ?: [$name];
        $initials = mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr((string) end($words), 0, 1) : ''));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#1f2937"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" font-weight="700" fill="#fff">'
            .htmlspecialchars($initials, ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
