<?php

namespace App\Core\Data\Workspaces;

use App\Core\Models\WorkspaceInvitation;

final readonly class CreatedWorkspaceInvitation
{
    public function __construct(
        public WorkspaceInvitation $invitation,
        public string $token,
    ) {}
}
