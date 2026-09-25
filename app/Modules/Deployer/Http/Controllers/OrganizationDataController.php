<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
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
        abort_unless($organization->permits($user, 'manage'), 403);
        // A complete workspace export requires access to every included resource.
        foreach (['projects', 'servers', 'websites', 'repositories'] as $relation) {
            $visible = 'workspace'.ucfirst($relation);
            $owned = $organization->{$relation}();
            $allowed = $user->{$visible}();
            if (in_array($relation, ['websites', 'repositories'], true)) {
                $owned->withTrashed();
                $allowed->withTrashed();
            }
            abort_if($owned->whereNotIn($relation.'.id', $allowed->select($relation.'.id'))->exists(), 403);
        }

        return $organization;
    }
}
