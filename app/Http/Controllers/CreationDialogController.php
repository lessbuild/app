<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardCreationDialogData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CreationDialogController extends Controller
{
    /**
     * Render a workspace-scoped creation form for a dialog hosted by the current page.
     *
     * The form is loaded only after the user opens the dialog, which keeps the
     * shared layout from loading inventory options on every authenticated page.
     */
    public function __invoke(
        Request $request,
        DashboardCreationDialogData $dialogData,
        string $resource,
    ): View {
        abort_unless(in_array($resource, ['provider', 'server', 'website', 'repository'], true), 404);

        /** @var User $user */
        $user = $request->user();
        $data = $dialogData->for($user);
        $cancelUrl = $this->safeReturnUrl($request);

        return match ($resource) {
            'provider' => view('components.scenes.providers.create-dialog-content', [
                'cancelUrl' => $cancelUrl,
            ]),
            'server' => view('components.scenes.servers.create-dialog-content', [
                ...$data['server'],
                'cancelUrl' => $cancelUrl,
            ]),
            'website' => view('components.scenes.websites.create-dialog-content', [
                ...$data['website'],
                'websiteStoreUrl' => route('websites.store', ['dialog' => 'create-website']),
                'cancelUrl' => $cancelUrl,
            ]),
            'repository' => view('components.scenes.repositories.create-dialog-content', [
                ...$data['repository'],
                'cancelUrl' => $cancelUrl,
            ]),
        };
    }

    /**
     * Accept only a same-origin return URL for the dialog's cancel action.
     */
    private function safeReturnUrl(Request $request): string
    {
        $candidate = $request->string('return_to')->toString();

        if ($candidate === '') {
            return route('dashboard');
        }

        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return route('dashboard');
        }

        if (isset($parts['scheme']) && $parts['scheme'] !== $request->getScheme()) {
            return route('dashboard');
        }

        return $candidate;
    }
}
