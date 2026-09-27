<x-signal.ui.badge :tone="match ($status) { 'succeeded' => 'success', 'failed', 'rejected' => 'danger', 'running', 'deploying' => 'info', 'awaiting_approval' => 'warning', default => 'neutral' }">{{ match ($status) {
    'succeeded' => __('Live'), 'failed' => __('Failed'), 'rejected' => __('Rejected'), 'canceled' => __('Canceled'),
    'running', 'deploying' => __('Deploying'), 'awaiting_approval' => __('Waiting for approval'), default => __('Queued'),
} }}</x-signal.ui.badge>
