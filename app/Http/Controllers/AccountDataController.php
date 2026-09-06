<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountDataController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizations = $user->organizations()->orderBy('organizations.id')->get();
        $current = $user->currentOrganization;

        $response = response()->json([
            'exported_at' => now()->utc()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'workspaces' => $organizations->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $organization->pivot->role,
                'is_current' => $organization->id === $user->current_organization_id,
            ])->values(),
            'current_workspace_data' => $current ? [
                'projects' => $current->projects()->with('environments:id,project_id,name,slug,type,branch,status')->get(['id', 'name', 'slug', 'description', 'created_at']),
                'providers' => $current->providers()->get(['id', 'name', 'description', 'provider', 'connection_status', 'connection_checked_at', 'created_at']),
                'servers' => $current->servers()->get(['id', 'name', 'display_name', 'public_ip', 'provisioning_status', 'created_at']),
                'websites' => $current->websites()->get(['id', 'name', 'url', 'description', 'provisioning_status', 'health_status', 'created_at']),
                'repositories' => $current->repositories()->get(['id', 'name', 'repository', 'branch', 'created_at']),
                'status_pages' => $current->statusPages()->get(['id', 'name', 'slug', 'description', 'is_published', 'created_at']),
            ] : null,
            'sign_ins' => $user->signIns()->latest('signed_in_at')->get(['method', 'ip_address', 'user_agent', 'signed_in_at']),
            'note' => 'Encrypted credentials, environment secrets, private keys, command contents, and retained logs are excluded for safety.',
        ], headers: [
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="buildpusher-account-export-'.now()->utc()->format('Ymd-His').'.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $response;
    }
}
