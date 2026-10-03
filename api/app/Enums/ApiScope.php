<?php

declare(strict_types=1);

namespace App\Enums;

/** What an API token may do. Services add their scopes here as they arrive (Phase 4). */
enum ApiScope: string
{
    case AccountRead = 'account:read';
    case ProjectsRead = 'projects:read';
    case ProjectsWrite = 'projects:write';
    case DeployRead = 'deploy:read';
    case DeployWrite = 'deploy:write';
    case InfrastructureRead = 'infrastructure:read';
    case InfrastructureWrite = 'infrastructure:write';
    case MonitoringRead = 'monitoring:read';
    case MonitoringWrite = 'monitoring:write';
    case AnalyticsRead = 'analytics:read';
    case AnalyticsWrite = 'analytics:write';
    case SecurityRead = 'security:read';
    case SecurityWrite = 'security:write';
    case AuditRead = 'audit:read';
    case AuditWrite = 'audit:write';

    /**
     * Get the part of the platform the scope covers, as shown on the token form.
     *
     * @return string
     */
    public function group(): string
    {
        return match ($this) {
            self::AccountRead => __('Account'),
            self::ProjectsRead, self::ProjectsWrite => __('Projects'),
            self::DeployRead, self::DeployWrite => __('Deploy'),
            self::InfrastructureRead, self::InfrastructureWrite => __('Infrastructure'),
            self::MonitoringRead, self::MonitoringWrite => __('Monitoring'),
            self::AnalyticsRead, self::AnalyticsWrite => __('Analytics'),
            self::SecurityRead, self::SecurityWrite => __('Security'),
            self::AuditRead, self::AuditWrite => __('Audit'),
        };
    }

    /**
     * Describe how the scope reads on the token form and token list, such as "Deploy: read and write".
     *
     * @return string
     */
    public function label(): string
    {
        return str_ends_with($this->value, ':write') ? __(':group: read and write', ['group' => $this->group()]) : __(':group: read', ['group' => $this->group()]);
    }

    /**
     * Write access implies read access to the same area.
     *
     * @return ApiScope|null
     */
    public function implied(): ?self
    {
        return str_ends_with($this->value, ':write') ? self::tryFrom(str_replace(':write', ':read', $this->value)) : null;
    }
}
