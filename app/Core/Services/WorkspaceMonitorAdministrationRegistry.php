<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceMonitorAlertAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorDestinationAdministrationProvider;
use App\Core\Contracts\WorkspaceMonitorSettingsAdministrationProvider;

/** Keeps Monitor's administration domains independently replaceable and typed. */
final class WorkspaceMonitorAdministrationRegistry
{
    private ?WorkspaceMonitorAlertAdministrationProvider $alerts = null;

    private ?WorkspaceMonitorDestinationAdministrationProvider $destinations = null;

    private ?WorkspaceMonitorSettingsAdministrationProvider $settings = null;

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
}
