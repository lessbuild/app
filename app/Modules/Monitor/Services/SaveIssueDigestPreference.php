<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\IssueDigestPreference;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;

final class SaveIssueDigestPreference
{
    public function save(Workspace $workspace, User $user, bool $enabled): IssueDigestPreference
    {
        return $workspace->issueDigestPreferences()->updateOrCreate(
            ['user_id' => $user->id],
            ['enabled' => $enabled, 'frequency' => $enabled ? 'daily' : 'off'],
        );
    }
}
