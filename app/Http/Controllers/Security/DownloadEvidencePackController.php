<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\ExportEvidencePack;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DownloadEvidencePackController
{
    /**
     * Build and download the evidence pack; the file is deleted once sent.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ExportEvidencePack  $export
     * @return BinaryFileResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ExportEvidencePack $export): BinaryFileResponse
    {
        $request->validate(['months' => ['required', 'integer', 'in:3,6,12']]);
        $path = $export->handle($user, $project, $request->integer('months'));

        return response()->download($path, str($project->name)->slug()->append('-security-evidence-', now()->format('Y-m-d'), '.zip')->toString(), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
