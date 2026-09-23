<?php

namespace App\Modules\Analytics\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Viewer = 'viewer';

    public function canManageWorkspace(): bool
    {
        return $this === self::Owner;
    }

    public function canManageSites(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
