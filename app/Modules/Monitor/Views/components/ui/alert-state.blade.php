@props(['rule'])
@php
    $state = $rule->trashed() ? 'archived' : (! $rule->enabled || $rule->environment->status !== 'active' ? 'paused' : $rule->evaluation_state);
    if (! in_array($state, ['archived', 'paused']) && $rule->next_evaluation_at->lt(now()->subMinutes(3))) {
        $state = 'delayed';
    }
    $label = match ($state) {
        'breaching' => 'Threshold breached', 'healthy' => 'Below threshold', 'no_data' => 'No data / insufficient samples', 'maintenance' => 'Maintenance window active',
        'paused' => 'Evaluations paused', 'archived' => 'Archived', 'delayed' => 'Evaluation overdue', default => 'Warming up',
    };
    $tone = match ($state) { 'breaching' => 'red', 'healthy' => 'green', 'no_data', 'delayed', 'maintenance' => 'amber', default => 'slate' };
@endphp
<x-monitor::ui.badge :tone="$tone">{{ $label }}</x-monitor::ui.badge>
