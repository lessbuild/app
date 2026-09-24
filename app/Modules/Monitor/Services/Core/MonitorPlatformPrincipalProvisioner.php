<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\AbstractProductPrincipalProvisioner;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\CreateWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class MonitorPlatformPrincipalProvisioner extends AbstractProductPrincipalProvisioner
{
    protected function userModel(): string
    {
        return User::class;
    }

    protected function product(): string
    {
        return 'monitor';
    }

    protected function afterProvision(Model $principal, PlatformUser $platformUser): void
    {
        abort_unless($principal instanceof User, 500);

        $workspaceName = trim((string) $platformUser->name)."'s workspace";
        if (Schema::connection('core')->hasTable('workspace_memberships')) {
            $workspaceName = $platformUser->workspaceMemberships()->with('workspace')->first()?->workspace?->name
                ?? $workspaceName;
        }

        app(CreateWorkspace::class)->create($principal, $workspaceName);
    }
}
