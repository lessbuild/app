<?php

namespace App\Core\Services;

use App\Core\Contracts\Analytics\WorkspaceAnalyticsDataAdministrationProvider;
use App\Core\Contracts\Analytics\WorkspaceAnalyticsGoalAdministrationProvider;
use App\Core\Contracts\Analytics\WorkspaceAnalyticsSiteAdministrationProvider;

final class WorkspaceAnalyticsAdministrationProviderRegistry
{
    private ?WorkspaceAnalyticsSiteAdministrationProvider $sites = null;

    private ?WorkspaceAnalyticsGoalAdministrationProvider $goals = null;

    private ?WorkspaceAnalyticsDataAdministrationProvider $data = null;

    public function registerSites(WorkspaceAnalyticsSiteAdministrationProvider $provider): void
    {
        $this->sites = $provider;
    }

    public function registerGoals(WorkspaceAnalyticsGoalAdministrationProvider $provider): void
    {
        $this->goals = $provider;
    }

    public function registerData(WorkspaceAnalyticsDataAdministrationProvider $provider): void
    {
        $this->data = $provider;
    }

    public function sites(): ?WorkspaceAnalyticsSiteAdministrationProvider
    {
        return $this->sites;
    }

    public function goals(): ?WorkspaceAnalyticsGoalAdministrationProvider
    {
        return $this->goals;
    }

    public function data(): ?WorkspaceAnalyticsDataAdministrationProvider
    {
        return $this->data;
    }
}
