<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Queries\Users\SignInOptionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/auth/options?invite=`. */
final class ShowSignInOptionsController
{
    /**
     * Return what the sign-in and sign-up pages offer: whether sign-up is open, the invited address, the social
     * providers, and the message from the last round trip.
     *
     * @param  Request  $request
     * @param  SignInOptionsQuery  $options
     * @return JsonResponse
     */
    public function __invoke(Request $request, SignInOptionsQuery $options): JsonResponse
    {
        $invite = $request->query('invite');

        return response()->json($options->handle(is_string($invite) && strlen($invite) === 64 ? $invite : null, $request->session()));
    }
}
