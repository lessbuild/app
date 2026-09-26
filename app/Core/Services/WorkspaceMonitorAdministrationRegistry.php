<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceMonitorAlertAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorConfigurationAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorDestinationAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorMaintenanceWindowAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorServiceObjectiveAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorSettingsAdministrationProvider;

/** Keeps Monitor's administration domains independently replaceable and typed. */
final class WorkspaceMonitorAdministrationRegistry
{
    private ?WorkspaceMonitorAlertAdministrationProvider $alerts = null;

    private ?WorkspaceMonitorDestinationAdministrationProvider $destinations = null;

    private ?WorkspaceMonitorSettingsAdministrationProvider $settings = null;

    private ?WorkspaceMonitorConfigurationAdministrationProvider $configuration = null;

    private ?WorkspaceMonitorMaintenanceWindowAdministrationProvider $maintenanceWindows = null;

    private ?WorkspaceMonitorServiceObjectiveAdministrationProvider $serviceObjectives = null;

    public function registerConfiguration(WorkspaceMonitorConfigurationAdministrationProvider $provider): void
    {
        $this->configuration = $provider;
    }

    public function registerAlerts(WorkspaceMonitorAlertAdministrationProvider $provider): void
    {
        $this->alerts = $provider;
    }

    public function registerDestinations(WorkspaceMonitorDestinationAdministrationProvider $provider): void
    {
        $this->destinations = $provider;
    }

    public function registerSettings(WorkspaceMonitorSettingsAdministrationProvider $provider): void
    {
        $this->settings = $provider;
    }

    public function registerMaintenanceWindows(WorkspaceMonitorMaintenanceWindowAdministrationProvider $provider): void
    {
        $this->maintenanceWindows = $provider;
    }

    public function registerServiceObjectives(WorkspaceMonitorServiceObjectiveAdministrationProvider $provider): void
    {
        $this->serviceObjectives = $provider;
    }

    public function alerts(): ?WorkspaceMonitorAlertAdministrationProvider
    {
        return $this->alerts;
    }

    public function destinations(): ?WorkspaceMonitorDestinationAdministrationProvider
    {
        return $this->destinations;
    }

    public function settings(): ?WorkspaceMonitorSettingsAdministrationProvider
    {
        return $this->settings;
    }

    public function configuration(): ?WorkspaceMonitorConfigurationAdministrationProvider
    {
        return $this->configuration;
    }

    public function maintenanceWindows(): ?WorkspaceMonitorMaintenanceWindowAdministrationProvider
    {
        return $this->maintenanceWindows;
    }

    public function serviceObjectives(): ?WorkspaceMonitorServiceObjectiveAdministrationProvider
    {
        return $this->serviceObjectives;
    }
}
