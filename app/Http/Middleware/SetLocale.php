<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Show the app in the person's language: the one they picked in their profile, otherwise their browser's preferred
 * language among those supported, otherwise the default. Dates follow the same language.
 */
final class SetLocale
{
    /**
     * Set the request's locale before it is handled.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->locale($request);
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /**
     * Pick the locale for this request.
     *
     * @param  Request  $request
     * @return string
     */
    private function locale(Request $request): string
    {
        /** @var array<string, string> $supported */
        $supported = config('app.supported_locales', []);
        $user = $request->user();
        if ($user instanceof User && $user->locale !== null && isset($supported[$user->locale])) {
            return $user->locale;
        }

        $preferred = $request->getPreferredLanguage(array_keys($supported));
        if ($preferred !== null && isset($supported[$preferred])) {
            return $preferred;
        }

        return (string) config('app.locale', 'en');
    }
}
