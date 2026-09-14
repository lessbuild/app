<?php

namespace App\Http\Controllers;

use App\Actions\Server\CloseServerTroubleshootingSessionAction;
use App\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Actions\Server\TouchServerTroubleshootingSessionAction;
use App\Data\ServerTroubleshootingSessionGrant;
use App\Data\ServerTroubleshootingSessionViewData;
use App\Models\Server;
use App\Models\ServerTroubleshootingSession;
use App\Models\User;
use App\Policies\ServerTroubleshootingSessionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerTroubleshootingController extends Controller
{
    public function __construct(private readonly ServerTroubleshootingSessionPolicy $sessions) {}

    /** Issue a short-lived grant without opening SSH or dispatching remote work. */
    public function store(Request $request, Server $server, OpenServerTroubleshootingSessionAction $open): JsonResponse
    {
        $this->authorize('connect', $server);
        $grant = $open->handle($server, $request->user());

        return $this->sessionResponse($grant, $request->user(), 201, includeToken: true);
    }

    /** Revalidate the bearer grant, refresh its idle deadline, and return safe lifecycle metadata. */
    public function show(
        Request $request,
        Server $server,
        ServerTroubleshootingSession $troubleshootingSession,
        TouchServerTroubleshootingSessionAction $touch,
    ): JsonResponse {
        $this->authorize('view', $troubleshootingSession);
        $token = $this->bearerToken($request);
        $touch->handle($troubleshootingSession, $request->user(), $token);
        $troubleshootingSession->refresh()->load('server');

        return $this->metadataResponse($troubleshootingSession, $request->user());
    }

    /** Close a grant without contacting the remote host; repeated close requests remain safe. */
    public function destroy(
        Request $request,
        Server $server,
        ServerTroubleshootingSession $troubleshootingSession,
        CloseServerTroubleshootingSessionAction $close,
    ): JsonResponse {
        $this->authorize('close', $troubleshootingSession);
        $token = $this->bearerToken($request);
        $closed = $close->handle($troubleshootingSession, $request->user(), $token);
        $troubleshootingSession->refresh()->load('server');

        return $this->metadataResponse($troubleshootingSession, $request->user(), ['closed' => $closed]);
    }

    /** @return array<string, bool|int|string|null> */
    private function metadata(ServerTroubleshootingSession $session, mixed $user): array
    {
        $canExecute = $session->isActive()
            && $user instanceof User
            && $this->sessions->execute($user, $session);

        return ServerTroubleshootingSessionViewData::fromSession($session, $canExecute)->toArray();
    }

    private function metadataResponse(
        ServerTroubleshootingSession $session,
        mixed $user,
        array $extra = [],
    ): JsonResponse {
        return $this->jsonResponse([
            'data' => $this->metadata($session, $user),
            ...$extra,
        ]);
    }

    private function sessionResponse(
        ServerTroubleshootingSessionGrant $grant,
        mixed $user,
        int $status,
        bool $includeToken = false,
    ): JsonResponse {
        $data = $this->metadata($grant->session->load('server'), $user);
        if ($includeToken) {
            $data['token'] = $grant->token;
        }

        return $this->jsonResponse(['data' => $data], $status);
    }

    private function bearerToken(Request $request): string
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            throw new AuthorizationException;
        }

        return $token;
    }

    private function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
