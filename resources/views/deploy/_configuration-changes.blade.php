{{-- A plan's changes as a table. --}}
<x-signal.ui.table :caption="__('Planned changes')">
    <x-slot:head><tr><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('What') }}</th><th scope="col">{{ __('Change') }}</th><th scope="col">{{ __('Fields') }}</th></tr></x-slot:head>
    @foreach ($plan['changes'] as $change)
        <tr>
            <td class="font-mono text-xs">{{ $change['environment'] }}</td>
            <td><span class="text-muted">{{ __(ucfirst(rtrim($change['kind'], 's'))) }}</span> <span class="font-mono text-xs">{{ $change['name'] }}</span></td>
            <td>
                <x-signal.ui.badge :tone="match ($change['action']) { 'create', 'deploy' => 'info', 'update' => 'neutral', 'adopt' => 'accent', 'adoption_required' => 'warning', 'remove', 'detach' => 'danger', default => 'neutral' }">{{ match ($change['action']) {
                    'create' => __('Create'), 'update' => __('Update'), 'adopt' => __('Adopt'), 'adoption_required' => __('Needs adopt: true'), 'remove' => __('Remove'),
                    'detach' => __('Detach'), 'absent' => __('Already gone'), 'deploy' => __('Deploy'), default => $change['action'],
                } }}</x-signal.ui.badge>
                @if (($change['requires_approval'] ?? false))<span class="text-xs text-muted">{{ __('needs approval') }}</span>@endif
            </td>
            <td class="font-mono text-xs text-muted">{{ implode(', ', $change['fields'] ?? []) ?: '—' }}</td>
        </tr>
    @endforeach
</x-signal.ui.table>
