<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use App\Queries\Users\PersonalDataExportQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportPersonalDataController
{
    /**
     * Downloads everything stored about the person as JSON, never cached.
     *
     * @param  User  $user
     * @param  PersonalDataExportQuery  $query
     * @return StreamedResponse
     */
    public function __invoke(#[CurrentUser] User $user, PersonalDataExportQuery $query): StreamedResponse
    {
        $json = json_encode($query->handle($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(fn () => print ($json), 'personal-data-'.now()->format('Y-m-d').'.json', [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
