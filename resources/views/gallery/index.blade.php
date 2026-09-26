<x-signal.layouts.base :title="__('Signal component gallery')">
    <main id="main-content" class="mx-auto w-full max-w-6xl space-y-10 px-4 py-10 sm:px-6">
        <x-signal.ui.page-header
            :eyebrow="__('Design system')"
            :title="__('Signal component gallery')"
            :description="__('Every shared primitive in one place. New screens compose these instead of adding one-off markup.')"
        />

        <section aria-labelledby="gallery-buttons" class="space-y-4">
            <h2 id="gallery-buttons" class="text-lg font-extrabold text-ink">{{ __('Buttons') }}</h2>
            <div class="flex flex-wrap items-center gap-3">
                @foreach (['primary', 'secondary', 'ghost', 'quiet', 'danger', 'inverse', 'outline', 'soft', 'link'] as $variant)
                    <x-signal.ui.button :variant="$variant">{{ str($variant)->headline() }}</x-signal.ui.button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-signal.ui.button variant="primary" size="sm">{{ __('Small') }}</x-signal.ui.button>
                <x-signal.ui.button variant="primary">{{ __('Default') }}</x-signal.ui.button>
                <x-signal.ui.button variant="primary" size="lg">{{ __('Large') }}</x-signal.ui.button>
                <x-signal.ui.button variant="primary" :disabled="true">{{ __('Disabled') }}</x-signal.ui.button>
            </div>
        </section>

        <section aria-labelledby="gallery-feedback" class="space-y-4">
            <h2 id="gallery-feedback" class="text-lg font-extrabold text-ink">{{ __('Badges and alerts') }}</h2>
            <div class="flex flex-wrap gap-2">
                @foreach (['neutral', 'accent', 'info', 'success', 'warning', 'danger'] as $tone)
                    <x-signal.ui.badge :tone="$tone">{{ str($tone)->headline() }}</x-signal.ui.badge>
                @endforeach
            </div>
            <div class="grid gap-3">
                @foreach (['info', 'success', 'warning', 'danger'] as $tone)
                    <x-signal.ui.alert :tone="$tone">{{ __('This is a :tone alert.', ['tone' => $tone]) }}</x-signal.ui.alert>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="gallery-data" class="space-y-4">
            <h2 id="gallery-data" class="text-lg font-extrabold text-ink">{{ __('Cards, stats and progress') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-signal.ui.stat :label="__('Deployments')" value="128" :description="__('Last 30 days')" />
                <x-signal.ui.stat :label="__('Uptime')" value="99.98%" tone="success" />
                <x-signal.ui.stat :label="__('Visitors')" value="24.1k" />
            </div>
            <x-signal.ui.card class="space-y-4 p-5">
                <p class="text-sm text-muted">{{ __('Cards group related content.') }}</p>
                <x-signal.ui.progress :value="64" :label="__('Monthly event allowance used')" />
            </x-signal.ui.card>
            <x-signal.ui.empty-state :title="__('No projects yet')" :description="__('Create a project to start using services.')" />
        </section>

        <section aria-labelledby="gallery-forms" class="space-y-4">
            <h2 id="gallery-forms" class="text-lg font-extrabold text-ink">{{ __('Forms') }}</h2>
            <x-signal.ui.card class="grid gap-4 p-5 sm:grid-cols-2">
                <x-signal.ui.input-field name="gallery_name" :label="__('Project name')" :description="__('Shown across every service.')" :restore="false" />
                <x-signal.ui.select-field name="gallery_region" :label="__('Region')">
                    <option>{{ __('Europe') }}</option>
                    <option>{{ __('North America') }}</option>
                </x-signal.ui.select-field>
                <x-signal.ui.textarea-field name="gallery_notes" :label="__('Notes')" :restore="false" />
                <div class="space-y-3">
                    <x-signal.ui.checkbox name="gallery_alerts" :restore="false">{{ __('Email me about incidents') }}</x-signal.ui.checkbox>
                    <x-signal.ui.choice id="gallery-plan" name="gallery_plan" :label="__('Pro tier')" :description="__('Longer retention and more checks.')" :card="true" type="radio" :restore="false" />
                </div>
            </x-signal.ui.card>
        </section>

        <section aria-labelledby="gallery-structure" class="space-y-4">
            <h2 id="gallery-structure" class="text-lg font-extrabold text-ink">{{ __('Tables, tabs and disclosure') }}</h2>
            <x-signal.ui.table :caption="__('Example services')">
                <x-slot:head>
                    <tr><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Tier') }}</th><th scope="col">{{ __('Status') }}</th></tr>
                </x-slot:head>
                <tr><td>{{ __('Deploy') }}</td><td>{{ __('Pro') }}</td><td><x-signal.ui.badge tone="success">{{ __('Active') }}</x-signal.ui.badge></td></tr>
                <tr><td>{{ __('Monitoring') }}</td><td>{{ __('Free') }}</td><td><x-signal.ui.badge>{{ __('Not enabled') }}</x-signal.ui.badge></td></tr>
            </x-signal.ui.table>
            <x-signal.ui.tablist :label="__('Example tabs')">
                <x-signal.ui.tab :selected="true">{{ __('Overview') }}</x-signal.ui.tab>
                <x-signal.ui.tab>{{ __('Settings') }}</x-signal.ui.tab>
            </x-signal.ui.tablist>
            <x-signal.ui.disclosure :title="__('Advanced options')">
                <p class="px-4 pb-4 text-sm text-muted">{{ __('Hidden until opened.') }}</p>
            </x-signal.ui.disclosure>
            <x-signal.ui.code-block code="curl https://api.buildpusher.com/v2/projects" />
        </section>

        <section aria-labelledby="gallery-overlays" class="space-y-4">
            <h2 id="gallery-overlays" class="text-lg font-extrabold text-ink">{{ __('Overlays') }}</h2>
            <x-signal.ui.button variant="primary" data-modal-trigger="gallery-modal" aria-controls="gallery-modal">{{ __('Open modal') }}</x-signal.ui.button>
            <x-signal.overlays.modal id="gallery-modal" :title="__('Enable Monitoring')" :description="__('Choose a tier for this project.')">
                <p class="text-sm text-muted">{{ __('Modal content goes here.') }}</p>
            </x-signal.overlays.modal>
        </section>
    </main>
</x-signal.layouts.base>
