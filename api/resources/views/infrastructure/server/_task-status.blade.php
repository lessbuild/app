{{-- Where a cron job, process or firewall rule stands on the server, with the server's error when it failed. --}}
@php($tones = ['active' => 'success', 'pending' => 'info', 'removing' => 'warning', 'failed' => 'danger'])
@php($labels = ['active' => __('In place'), 'pending' => __('Setting up'), 'removing' => __('Removing'), 'failed' => __('Failed')])
<x-signal.ui.badge :tone="$tones[$task->status] ?? 'neutral'" :title="$task->error">{{ $labels[$task->status] ?? $task->status }}</x-signal.ui.badge>
@if ($task->status === 'failed' && $task->error)<p class="mt-1 text-xs text-danger">{{ $task->error }}</p>@endif
