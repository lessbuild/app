<?php

namespace App\Http\Controllers;

use App\Actions\Repository\DisableRepositoryWebhookAction;
use App\Actions\Repository\EnableRepositoryWebhookAction;
use App\Http\Requests\RepositoryWebhookSettingsRequest;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

class RepositoryWebhookSettingsController extends Controller
{
    /**
     * Enable an editable repository's webhook using a validated GitLab signing token or a newly generated secret.
     *
     * @return RedirectResponse Webhook settings; generated non-GitLab secrets are flashed for one-time display.
     */
    public function store(
        RepositoryWebhookSettingsRequest $request,
        Repository $repository,
        EnableRepositoryWebhookAction $enable,
    ): RedirectResponse {
        $secret = $enable->handle($repository, $request->signingToken());
        if ($secret !== null) {
            session()->flash("repository:{$repository->id}:webhook_secret", $secret);
        }

        return redirect(route('repositories.show', $repository).'#deployment-webhook')
            ->with('success', __('Deployment webhook enabled.'));
    }

    /**
     * Disable an editable repository's webhook, clear its secret and pending revision, and redirect to webhook settings.
     */
    public function destroy(Repository $repository, DisableRepositoryWebhookAction $disable): RedirectResponse
    {
        $this->authorize('update', $repository);
        $disable->handle($repository);

        return redirect(route('repositories.show', $repository).'#deployment-webhook')
            ->with('success', __('Deployment webhook disabled.'));
    }
}
