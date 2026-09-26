<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\Deletion\DeletionMessages;
use App\Core\Services\Deletion\DeletionPlanner;
use App\Core\Services\Deletion\ProcessDeletion;
use App\Core\Services\Deletion\RequestDeletion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Symfony\Component\HttpFoundation\Response;

final class PlatformDeletionController
{
    public function account(Request $request, DeletionPlanner $planner): Response
    {
        return $this->review($request, $planner, null);
    }

    public function workspace(Request $request, Workspace $workspace, DeletionPlanner $planner): Response
    {
        return $this->review($request, $planner, $workspace);
    }

    private function review(Request $request, DeletionPlanner $planner, ?Workspace $workspace): Response
    {
        $user = $this->user($request);
        abort_if($workspace !== null && (string) $workspace->owner_user_id !== (string) $user->getKey(), 404);
        $existing = DeletionRequest::query()->where('actor_id', $user->getKey())
            ->where('kind', $workspace === null ? 'account' : 'workspace')
            ->where('target_id', $workspace?->getKey() ?? $user->getKey())->first();
        if ($existing !== null) {
            return $this->privateResponse(redirect()->route('platform.deletions.progress', $existing->idempotency_key));
        }
        try {
            $plan = $planner->plan($user, $workspace);
        } catch (DeletionBlocked $exception) {
            abort(409, DeletionMessages::reason($exception->reasonCode));
        }
        $receiptKey = (string) Str::uuid();
        $receiptToken = bin2hex(random_bytes(32));

        return $this->privateResponse(response()->view('core::deletions.review', compact('user', 'workspace', 'plan', 'receiptKey', 'receiptToken'))
            ->withCookie($this->receiptCookie($receiptKey, $receiptToken)));
    }

    public function storeAccount(Request $request, RequestDeletion $deletions): Response
    {
        return $this->store($request, $deletions, null);
    }

    public function storeWorkspace(Request $request, Workspace $workspace, RequestDeletion $deletions): Response
    {
        return $this->store($request, $deletions, $workspace);
    }

    private function store(Request $request, RequestDeletion $deletions, ?Workspace $workspace): Response
    {
        $user = $this->user($request);
        abort_if($workspace !== null && (string) $workspace->owner_user_id !== (string) $user->getKey(), 404);
        // Never flash passwords, authentication codes, confirmation text, or recovery secrets into sessions.
        $validator = Validator::make($request->all(), [
            'idempotency_key' => ['required', 'uuid'], 'fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirmation' => ['required', 'string', 'max:255'], 'understood' => ['accepted'],
            'current_password' => ['nullable', 'string', 'max:1000'], 'code' => ['nullable', 'string', 'max:100'],
        ]);
        $back = $workspace === null ? route('platform.deletions.account.create') : route('platform.deletions.workspace.create', $workspace);
        if ($validator->fails()) {
            return $this->privateResponse(redirect($back)->withErrors($validator));
        }
        $input = $validator->validated();
        $token = $request->cookie($this->cookieName($input['idempotency_key']));
        abort_unless(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token), 419, 'Open the deletion review in this browser again.');
        try {
            $deletion = $deletions->request($user, $workspace, $input, $token, (string) $request->session()->get('platform.auth.session_id'));
        } catch (DeletionBlocked $exception) {
            return $this->privateResponse(redirect($back)->withErrors(['deletion' => DeletionMessages::reason($exception->reasonCode)]));
        } catch (ValidationException $exception) {
            return $this->privateResponse(redirect($back)->withErrors($exception->errors()));
        }
        if ($workspace === null) {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->privateResponse(redirect()->route('platform.deletions.progress', $deletion->idempotency_key));
    }

    public function progress(Request $request, string $receiptKey): Response
    {
        $deletion = DeletionRequest::query()->where('idempotency_key', $receiptKey)->first();
        if ($deletion === null || ! $this->canRead($request, $deletion)) {
            // The same response for a missing or unauthorized receipt prevents request enumeration.
            return $this->privateResponse(response()->view('core::deletions.recover', compact('receiptKey')));
        }
        $steps = $deletion->steps()->orderBy('product')->orderByDesc('kind')->get();
        $receiptToken = $request->cookie($this->cookieName($receiptKey));

        return $this->privateResponse(response()->view('core::deletions.progress', compact('deletion', 'steps', 'receiptKey', 'receiptToken')));
    }

    public function recover(Request $request, string $receiptKey): Response
    {
        $token = $request->input('token');
        $deletion = DeletionRequest::query()->where('idempotency_key', $receiptKey)->first();
        if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token) || $deletion === null
            || ! hash_equals($deletion->receipt_token_hash, hash('sha256', $token))) {
            return $this->privateResponse(redirect()->route('platform.deletions.progress', $receiptKey)
                ->withErrors(['token' => __('The receipt could not be verified. Check the recovery key and try again.')]));
        }

        return $this->privateResponse(redirect()->route('platform.deletions.progress', $receiptKey)
            ->withCookie($this->receiptCookie($receiptKey, $token)));
    }

    public function retry(Request $request, string $receiptKey, ProcessDeletion $processor): Response
    {
        $deletion = DeletionRequest::query()->where('idempotency_key', $receiptKey)->first();
        abort_unless($deletion !== null && $this->canRead($request, $deletion), 404);
        // A receipt can only retry the already accepted, immutable scope. It cannot create a new request.
        $processor->retry($deletion);

        return $this->privateResponse(redirect()->route('platform.deletions.progress', $receiptKey));
    }

    private function canRead(Request $request, DeletionRequest $deletion): bool
    {
        $token = $request->cookie($this->cookieName($deletion->idempotency_key));
        if (is_string($token) && hash_equals($deletion->receipt_token_hash, hash('sha256', $token))) {
            return true;
        }
        $user = $request->user('platform');

        return $deletion->kind === 'workspace' && $user instanceof PlatformUser && $user->status === 'active'
            && (string) $deletion->actor_id === (string) $user->getKey();
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 401);

        return $user;
    }

    private function cookieName(string $key): string
    {
        return 'deletion_receipt_'.$key;
    }

    private function receiptCookie(string $key, string $token): HttpCookie
    {
        $path = dirname((string) parse_url(route('platform.deletions.account.create'), PHP_URL_PATH));

        return new HttpCookie($this->cookieName($key), $token, now()->addYear(), $path, null,
            app()->environment('production') || request()->isSecure(), true, false, 'lax');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
