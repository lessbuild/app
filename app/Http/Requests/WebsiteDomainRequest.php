<?php

namespace App\Http\Requests;

use App\Models\Website;
use Illuminate\Foundation\Http\FormRequest;

abstract class WebsiteDomainRequest extends FormRequest
{
    private ?Website $resolvedWebsite = null;

    /**
     * Resolve the submitted website within the actor's current workspace, retaining the existing 404 boundary.
     */
    public function website(): Website
    {
        return $this->resolvedWebsite ??= $this->user()->workspaceWebsites()->findOrFail((int) $this->input('website_id'));
    }

    /**
     * Preserve the existing website update policy before domain input validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('update', $this->website());
    }
}
