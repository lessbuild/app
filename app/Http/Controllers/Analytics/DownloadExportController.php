<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsExport;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadExportController
{
    public function __invoke(Project $project, string $token): StreamedResponse
    {
        $export = AnalyticsExport::query()->where('token_hash', hash('sha256', $token))->whereHas('site', fn ($query) => $query->where('project_id', $project->id))->firstOrFail();
        abort_unless($export->status === 'completed' && $export->expires_at->isFuture() && $export->file_path !== null, 410, __('This export is no longer available.'));
        abort_unless(Storage::disk('local')->exists($export->file_path), 404);

        return Storage::disk('local')->download($export->file_path, 'analytics-'.Str::slug($export->site->name).'.csv');
    }
}
