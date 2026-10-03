<x-signal.layouts.base :title="__(':project release notes', ['project' => $environment->project->name])" :description="__('What changed in each release of :project.', ['project' => $environment->project->name])">
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-3xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow">{{ $environment->project->name }}</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Release notes') }}</h1>
        </header>
        @forelse ($builds as $build)
            @php($sections = \App\Support\Deploy\ReleaseNotes::sections($build->release_commits ?? []))
            @continue($sections === [])
            <x-signal.ui.card as="article" class="grid gap-3 p-5 sm:p-6">
                <h2 class="font-extrabold text-ink">{{ $build->activated_at?->toFormattedDateString() }}</h2>
                @foreach ($sections as $section => $notes)
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wide text-muted">{{ __($section) }}</h3>
                        <ul class="mt-1 list-disc pl-5 text-sm text-ink">
                            @foreach ($notes as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </x-signal.ui.card>
        @empty
            <p class="text-sm text-muted">{{ __('No releases yet.') }}</p>
        @endforelse
    </main>
</x-signal.layouts.base>
