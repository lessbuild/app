{{-- Request and error figures for a release or one side of a deployment. --}}
<div class="grid gap-4 sm:grid-cols-4">
    <x-signal.ui.stat :label="__('Requests')" :value="number_format($metrics['requests'])" />
    <x-signal.ui.stat :label="__('Error rate')" :value="$metrics['errorRate'] !== null ? number_format($metrics['errorRate'], 2).'%' : '—'" />
    <x-signal.ui.stat :label="__('Average response')" :value="$metrics['averageDuration'] !== null ? number_format($metrics['averageDuration'], 1).' ms' : '—'" />
    <x-signal.ui.stat :label="__('Exceptions')" :value="number_format($metrics['exceptions'])" />
</div>
