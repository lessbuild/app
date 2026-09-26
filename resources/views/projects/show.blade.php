@php($project = $overview->project)
@php($enabled = $overview->enabledServices())

<x-signal.layouts.project :overview="$overview" :title="$project->name" :description="$project->description">
    @if ($enabled === [] && $overview->canManage)
        <x-signal.ui.panel as="section" class="space-y-2 p-6" aria-labelledby="next-step-heading">
            <p class="ui-eyebrow">{{ __('Next step') }}</p>
            <h2 id="next-step-heading" class="text-lg font-extrabold text-ink">{{ __('Turn on the services this project needs') }}</h2>
            <p class="text-sm text-muted">{{ __('You can change this any time. Turning a service off keeps its data.') }}</p>
        </x-signal.ui.panel>
    @endif

    <section aria-labelledby="services-heading" class="space-y-4">
        <h2 id="services-heading" class="text-lg font-extrabold text-ink">{{ __('Services') }}</h2>
        <ul class="grid gap-4 sm:grid-cols-2">
            @foreach ($overview->services as $service)
                <li>@include('projects._service-card', ['service' => $service, 'project' => $project])</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="environments-heading" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="environments-heading" class="text-lg font-extrabold text-ink">{{ __('Environments') }}</h2>
            @if ($overview->canManage)
                <x-signal.ui.button :href="route('projects.settings', $project).'#environments'" variant="quiet" size="sm">{{ __('Manage environments') }}</x-signal.ui.button>
            @endif
        </div>
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line">
                @foreach ($overview->environments as $environment)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <span class="font-bold text-ink">{{ $environment->name }}</span>
                        <x-signal.ui.badge :tone="$environment->kind === \App\Domain\Projects\Enums\EnvironmentKind::Production ? 'accent' : 'neutral'">{{ $environment->kind->label() }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    </section>
</x-signal.layouts.project>
