<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

enum AccountPermission: string
{
    case ViewAccount = 'account.view';
    case ManageSettings = 'account.settings';
    case ManageMembers = 'account.members';
    case ManageBilling = 'account.billing';
    case ViewBilling = 'account.billing.view';
    case ManageProjects = 'projects.manage';
    case ViewProjects = 'projects.view';
    case ManageApiTokens = 'account.api-tokens';
    case ViewAuditLog = 'account.audit-log';
    case DeleteAccount = 'account.delete';
}
