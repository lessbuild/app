<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\AddAnnotation;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreAnnotationController
{
    /**
     * Add a note to a site's chart and return to the report.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  AddAnnotation  $add
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, AddAnnotation $add): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'text' => ['required', 'string', 'max:200']]);
        $add->handle($user, $site, CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], $site->timezone) ?: CarbonImmutable::now($site->timezone), $data['text']);

        return redirect()->to(url()->previous(route('analytics.overview', [$project, 'site' => $site->id])))->with('status', __('Note added.'));
    }
}
