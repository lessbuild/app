<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\ExportOrganizationData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrganizationDataController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->authorizeManager($request);

        return view('scenes.organizations.data', [
            'organization' => $organization,
        ]);
    }

    public function export(Request $request, ExportOrganizationData $export): StreamedResponse
    {
        $organization = $this->authorizeManager($request);

        return response()->streamDownload(function () use ($organization, $export): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                abort(500, 'The Deployer workspace export could not be opened.');
            }

            try {
                $export->write($organization, $output);
            } finally {
                fclose($output);
            }
        }, 'buildpusher-deployer-'.Str::slug($organization->slug).'-'.now('UTC')->format('Ymd-His').'.ndjson', [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeManager(Request $request): Organization
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        abort_unless($organization->permits($user, 'manage') && app(DeployerProjectAccess::class)->workspace($user), 403);
        // A complete workspace export requires access to every included resource.
        foreach (['projects', 'servers', 'websites', 'repositories', 'providers', 'statusPages'] as $relation) {
            $owned = $organization->{$relation}();
            if (in_array($relation, ['websites', 'repositories', 'providers'], true)) {
                $owned->withTrashed();
            }
            $column = $owned->getModel()->qualifyColumn('id');
            $allowed = app(DeployerProjectAccess::class)->{$relation}(clone $owned, $user, ProjectResourceAccessPurpose::HistoricalExport);
            abort_if($owned->whereNotIn($column, $allowed->select($column))->exists(), 403);
        }

        $builds = Build::query()->whereIn('repository_id', Repository::withTrashed()->where('organization_id', $organization->getKey())->select('id'));
        $allowedBuilds = app(DeployerProjectAccess::class)->builds(clone $builds, $user, ProjectResourceAccessPurpose::HistoricalExport);
        abort_if($builds->whereNotIn('builds.id', $allowedBuilds->select('builds.id'))->exists(), 403);

        return $organization;
    }
}
