<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Server\AcknowledgeServerTroubleshootingOutputFramesAction;
use App\Modules\Deployer\Actions\Server\CloseServerTroubleshootingSessionAction;
use App\Modules\Deployer\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Modules\Deployer\Actions\Server\QueueServerTroubleshootingInputFrameAction;
use App\Modules\Deployer\Actions\Server\QueueServerTroubleshootingResizeFrameAction;
use App\Modules\Deployer\Actions\Server\ReadServerTroubleshootingOutputFramesAction;
use App\Modules\Deployer\Actions\Server\TouchServerTroubleshootingSessionAction;
use App\Modules\Deployer\Data\ServerTroubleshootingFrameData;
use App\Modules\Deployer\Data\ServerTroubleshootingSessionGrant;
use App\Modules\Deployer\Data\ServerTroubleshootingSessionViewData;
use App\Modules\Deployer\Data\ServerTroubleshootingTerminalSize;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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

    /** Queue one bounded shell-input frame without returning its sensitive payload. */
    public function input(
        Request $request,
        Server $server,
        ServerTroubleshootingSession $troubleshootingSession,
        QueueServerTroubleshootingInputFrameAction $queue,
    ): JsonResponse {
        $this->authorize('execute', $troubleshootingSession);
        $token = $this->bearerToken($request);
        $frame = $queue->handle(
            $troubleshootingSession,
            $request->user(),
            $token,
            $this->inputPayload($request),
        );

        return $this->jsonResponse([
            'data' => [
                'accepted' => true,
                'id' => (string) $frame->id,
                'sequence' => $frame->sequence,
                'bytes' => $frame->bytes,
            ],
        ], 202);
    }

    /** Read a bounded ordered output window; the client acknowledges it explicitly. */
    public function output(
        Request $request,
        Server $server,
        ServerTroubleshootingSession $troubleshootingSession,
        ReadServerTroubleshootingOutputFramesAction $read,
    ): JsonResponse {
        $this->authorize('connect', $troubleshootingSession);
        $token = $this->bearerToken($request);
        $after = max(0, $this->integerValue($request->query('after', 0), 'after'));
        $limit = max(1, min(100, $this->integerValue($request->query('limit', 50), 'limit')));
        $frames = $read->handle($troubleshootingSession, $request->user(), $token, $after, $limit);
        $nextAfter = $frames === [] ? $after : $frames[count($frames) - 1]->sequence;

        return $this->jsonResponse([
            'data' => [
                'frames' => array_map(
                    static fn (ServerTroubleshootingFrameData $frame): array => [
                        'sequence' => $frame->sequence,
                        'payload' => $frame->payload,
                        'bytes' => $frame->bytes,
                    ],
                    $frames,
                ),
                'after' => $after,
                'next_after' => $nextAfter,
            ],
        ]);
    }

    /** Acknowledge output through a sequence without acknowledging input frames. */
    public function acknowledgeOutput(
        Request $request,
        Server $server,
        ServerTroubleshootingSession $troubleshootingSession,
        AcknowledgeServerTroubleshootingOutputFramesAction $acknowledge,
    ): JsonResponse {
        $this->authorize('connect', $troubleshootingSession);
        $token = $this->bearerToken($request);
        $through = max(0, $this->integerValue($request->input('through'), 'through'));
        $count = $acknowledge->handle($troubleshootingSession, $request->user(), $token, $through);

        return $this->jsonResponse([
            'data' => [
                'acknowledged' => $count,
                'through' => $through,
            ],
        ]);
    }

    /** Queue validated terminal dimensions as a broker-delivered control frame. */
    public function resize(
        Request $request,
        Server $server,
        ServerTroubleshootingSession $troubleshootingSession,
        QueueServerTroubleshootingResizeFrameAction $resize,
    ): JsonResponse {
        $this->authorize('execute', $troubleshootingSession);
        $token = $this->bearerToken($request);
        $frame = $resize->handle(
            $troubleshootingSession,
            $request->user(),
            $token,
            $this->terminalSize($request),
        );

        return $this->jsonResponse([
            'data' => [
                'accepted' => true,
                'sequence' => $frame->sequence,
                'bytes' => $frame->bytes,
            ],
        ], 202);
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

    private function inputPayload(Request $request): string
    {
        $payload = $request->input('input');
        if (! is_string($payload)) {
            throw ValidationException::withMessages([
                'input' => __('The input field must be a string.'),
            ]);
        }

        return $payload;
    }

    private function terminalSize(Request $request): ServerTroubleshootingTerminalSize
    {
        $columns = $this->integerValue($request->input('columns'), 'columns');
        $rows = $this->integerValue($request->input('rows'), 'rows');

        try {
            return new ServerTroubleshootingTerminalSize($columns, $rows);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['terminal' => $exception->getMessage()]);
        }
    }

    private function integerValue(mixed $value, string $key): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
        }

        throw ValidationException::withMessages([
            $key => __('The :field must be an integer.', ['field' => $key]),
        ]);
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
