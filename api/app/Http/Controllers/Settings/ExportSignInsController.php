<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\SignInEvent;
use App\Models\User;
use App\Support\CsvDownload;
use Generator;
use Illuminate\Container\Attributes\CurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** `GET /api/app/settings/sign-ins.csv`. */
final class ExportSignInsController
{
    /**
     * Download the person's sign-in history: when, whether it worked, how, with two-factor or not, and from where.
     *
     * @param  User  $user
     * @return StreamedResponse
     */
    public function __invoke(#[CurrentUser] User $user): StreamedResponse
    {
        $rows = (function () use ($user): Generator {
            foreach (SignInEvent::query()->where('user_id', $user->id)->latest('created_at')->lazy(200) as $event) {
                yield [$event->created_at->toIso8601String(), $event->succeeded ? 'yes' : 'no', $event->method?->value, $event->two_factor ? 'yes' : 'no', $event->ip_address, $event->user_agent];
            }
        })();

        return CsvDownload::stream('sign-ins-'.now('UTC')->format('Ymd-His').'.csv', ['when', 'succeeded', 'method', 'two_factor', 'ip_address', 'browser'], $rows);
    }
}
