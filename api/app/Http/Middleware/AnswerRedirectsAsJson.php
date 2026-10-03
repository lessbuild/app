<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the app's API (/api/app): when a check sends the person somewhere first (verify their email, set up a second
 * factor, confirm single sign-on, sign in again after being idle), answer 409 with where to go and why, so the app can
 * take them there.
 */
final class AnswerRedirectsAsJson
{
    /**
     * Turn a redirect into a 409 the app follows.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof RedirectResponse) {
            return $response;
        }
        $target = parse_url($response->getTargetUrl());
        $session = $request->hasSession() ? $request->session() : null;
        $message = $session?->get('warning') ?? $session?->get('status');

        return new JsonResponse([
            'redirect' => ($target['path'] ?? '/').(isset($target['query']) ? '?'.$target['query'] : ''),
            'message' => is_string($message) ? $message : null,
        ], 409);
    }
}
