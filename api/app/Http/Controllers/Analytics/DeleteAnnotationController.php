<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RemoveAnnotation;
use App\Models\AnalyticsAnnotation;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteAnnotationController
{
    /**
     * Remove a note from a site's chart and return to the report. Another site's notes are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $annotation
     * @param  RemoveAnnotation  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $annotation, RemoveAnnotation $remove): JsonResponse
    {
        $remove->handle($user, AnalyticsAnnotation::query()->where('site_id', $site->id)->findOrFail($annotation));

        return response()->json(['message' => __('Note removed.')]);
    }
}
