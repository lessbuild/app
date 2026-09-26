<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

enum AccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Billing = 'billing';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Administrator'),
            self::Member => __('Member'),
            self::Billing => __('Billing manager'),
            self::Viewer => __('Viewer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Full access, including deleting the account.'),
            self::Admin => __('Manages members, settings, API tokens and every project.'),
            self::Member => __('Works on projects and the services enabled for them.'),
            self::Billing => __('Manages plans, payment methods and invoices only.'),
            self::Viewer => __('Read-only access to projects.'),
        };
    }

    /** @return list<AccountPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => AccountPermission::cases(),
            self::Admin => [
                AccountPermission::ViewAccount, AccountPermission::ManageSettings, AccountPermission::ManageMembers,
                AccountPermission::ManageBilling, AccountPermission::ViewBilling, AccountPermission::ManageProjects,
                AccountPermission::ViewProjects, AccountPermission::ManageApiTokens, AccountPermission::ViewAuditLog,
            ],
            self::Member => [AccountPermission::ViewAccount, AccountPermission::ViewProjects, AccountPermission::ManageProjects, AccountPermission::ViewBilling],
            self::Billing => [AccountPermission::ViewAccount, AccountPermission::ViewBilling, AccountPermission::ManageBilling],
            self::Viewer => [AccountPermission::ViewAccount, AccountPermission::ViewProjects],
        };
    }

    public function allows(AccountPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Roles an actor holding this role may assign to others. */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Admin => $role !== self::Owner,
            default => false,
        };
    }
}
