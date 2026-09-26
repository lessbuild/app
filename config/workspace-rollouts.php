<?php

$workspaceIds = static function (string $key): ?array {
    $value = env($key);

    return $value === null ? null : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
};

return [
    // Null allows every workspace; a comma-separated allowlist permits a pilot.
    // These existing shared views remain on by default. Flags never grant app access.
    'features' => [
        'credential_inventory' => [
            'label' => 'Shared credential inventory',
            'description' => 'Review safe credential metadata from connected apps in one shared view.',
            'available' => env('CORE_ROLLOUT_CREDENTIAL_INVENTORY_AVAILABLE', true),
            'default_enabled' => true,
            'workspace_managed' => env('CORE_ROLLOUT_CREDENTIAL_INVENTORY_MANAGED', true),
            'workspace_ids' => $workspaceIds('CORE_ROLLOUT_CREDENTIAL_INVENTORY_WORKSPACES'),
        ],
        'delivery_history' => [
            'label' => 'Shared delivery history',
            'description' => 'Review webhook and integration delivery summaries across connected apps.',
            'available' => env('CORE_ROLLOUT_DELIVERY_HISTORY_AVAILABLE', true),
            'default_enabled' => true,
            'workspace_managed' => env('CORE_ROLLOUT_DELIVERY_HISTORY_MANAGED', true),
            'workspace_ids' => $workspaceIds('CORE_ROLLOUT_DELIVERY_HISTORY_WORKSPACES'),
        ],
    ],
];
