<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ImportAdSpend;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportAdSpendController
{
    /**
     * Import an uploaded CSV of ad spend and return to the campaigns page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ImportAdSpend  $import
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ImportAdSpend $import): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/tab-separated-values'],
            'source' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\- ]+$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $count = $import->handle($user, $site, (string) $request->file('file')?->get(), $data['source'], $data['currency']);

        return response()->json(['redirect' => route('analytics.campaigns', [$project, 'site' => $site->id], false), 'message' => trans_choice('Imported :count day of spend.|Imported :count days of spend.', $count, ['count' => number_format($count)])]);
    }
}
