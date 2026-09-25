<?php

namespace App\Core\Http\Middleware;

use App\Core\Enums\WorkspaceRolloutOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceFeatureRollouts;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class EnsureWorkspaceFeatureRollout
{
    public const DEGRADED_ATTRIBUTE = 'core.rollout.degraded';

    public function __construct(private readonly WorkspaceFeatureRollouts $rollouts) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user('platform');
        $workspace = $request->route('workspace');
        abort_unless($user instanceof PlatformUser, 401);
        abort_unless($workspace instanceof Workspace, 404);
        $membership = $this->rollouts->authorize($user, $workspace);
        $state = $this->rollouts->state($workspace, $feature);

        if (! $state['enabled']) {
            $this->rollouts->record($workspace, $feature, WorkspaceRolloutOutcome::Held);

            return response()->view('core::workspaces.feature-unavailable', [
                'user' => $user, 'workspace' => $workspace, 'feature' => $state,
                'canManage' => in_array($membership->role, ['owner', 'admin'], true),
            ], 403)->header('Cache-Control', 'private, no-store');
        }

        $this->rollouts->record($workspace, $feature, WorkspaceRolloutOutcome::Exposed);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $rejected = $exception instanceof ValidationException
                || $exception instanceof AuthorizationException
                || $exception instanceof AuthenticationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
            $this->rollouts->record($workspace, $feature, $rejected
                ? WorkspaceRolloutOutcome::Rejected : WorkspaceRolloutOutcome::Failed);

            throw $exception;
        }

        $outcome = match (true) {
            $response->getStatusCode() >= 500 => WorkspaceRolloutOutcome::Failed,
            $response->getStatusCode() >= 400 => WorkspaceRolloutOutcome::Rejected,
            $request->attributes->get(self::DEGRADED_ATTRIBUTE) === true => WorkspaceRolloutOutcome::Degraded,
            default => WorkspaceRolloutOutcome::Completed,
        };
        $this->rollouts->record($workspace, $feature, $outcome);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
