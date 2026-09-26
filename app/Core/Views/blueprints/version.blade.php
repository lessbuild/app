<x-signal.layouts.platform :title="$blueprint->name" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces">
    <x-signal.ui.page-header :eyebrow="__('Version :version', ['version' => $version->version])" :title="$blueprint->name" :description="$blueprint->description">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.blueprints.index', $workspace)" variant="secondary">{{ __('All blueprints') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    <x-signal.ui.card as="form" method="GET" :action="route('core.workspace.blueprints.show', [$workspace, $version])" class="mt-6 flex flex-wrap items-end gap-4 p-5">
        <x-signal.ui.input-field name="q" type="search" :label="__('Find a project')" :value="$projectSearch" maxlength="120" />
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Search projects') }}</x-signal.ui.button>
    </x-signal.ui.card>
    @if ($projectsTruncated)
        <p class="mt-3 text-sm text-muted">{{ __('Showing the first 200 matches. Search by project name to narrow the list.') }}</p>
    @endif
    <x-signal.ui.card as="form" method="GET" :action="route('core.workspace.blueprints.show', [$workspace, $version])" class="mt-4 flex flex-wrap items-end gap-4 p-5">
        <x-signal.ui.input type="hidden" name="q" :value="$projectSearch" />
        <x-signal.ui.select-field name="project_id" :label="__('Target project')" required>
            @foreach ($projects as $candidate)<option value="{{ $candidate->getKey() }}" @selected($project?->getKey() === $candidate->getKey())>{{ $candidate->name }}</option>@endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Choose project') }}</x-signal.ui.button>
    </x-signal.ui.card>
    @if ($project)
        <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.blueprints.preview', [$workspace, $version])" class="mt-5 space-y-4 p-5">
            @csrf
            <x-signal.ui.input type="hidden" name="project_id" :value="$project->getKey()" />
            <h2 class="font-bold text-ink">{{ __('Environment bindings') }}</h2>
            @foreach ($version->definition['environments'] as $definition)
                <x-signal.ui.select-field :name="'environments['.$definition['key'].']'" :label="$definition['name'].' · '.str($definition['type'])->headline()">
                    <option value="">{{ __('Create :name', ['name' => $definition['name']]) }}</option>
                    @foreach ($environments->where('environment_type', $definition['type']) as $environment)
                        <option value="{{ $environment->getKey() }}" @selected(($selections[$definition['key']] ?? null) === $environment->getKey())>{{ __('Use :name', ['name' => $environment->name]) }}</option>
                    @endforeach
                </x-signal.ui.select-field>
            @endforeach
            <x-signal.ui.button type="submit">{{ __('Preview changes') }}</x-signal.ui.button>
        </x-signal.ui.card>
    @else
        <x-signal.ui.empty-state class="mt-5" :title="__('Choose or create a project first')" :description="__('A blueprint applies to an active shared project you can manage.')" />
    @endif
    @if ($preview)
        <section class="mt-6 space-y-4" aria-label="{{ __('Blueprint preview') }}">
            <x-signal.ui.alert tone="info">{{ __('Review these resources and current plan limits. Existing subscriptions stay as they are. Domains, trackers, secrets, and first deployments still require their normal setup and verification.') }}</x-signal.ui.alert>
            @foreach ($preview->products as $product => $productPreview)
                <x-signal.ui.card class="p-5">
                    <h2 class="font-bold text-ink">{{ config('platform.products.'.$product.'.label', str($product)->headline()) }}</h2>
                    <ul class="mt-3 list-inside list-disc space-y-1 text-sm text-muted">@foreach ($productPreview->changes as $change)<li>{{ $change }}</li>@endforeach</ul>
                    @if ($productPreview->planImpact !== [])<dl class="mt-4 grid gap-2 text-sm">@foreach ($productPreview->planImpact as $label => $value)<div><dt class="inline font-semibold">{{ str($label)->headline() }}:</dt> <dd class="inline">{{ $value ?? __('Unlimited') }}</dd></div>@endforeach</dl>@endif
                    @foreach ($productPreview->blockers as $blocker)<x-signal.ui.alert tone="warning" class="mt-3">{{ $blocker }}</x-signal.ui.alert>@endforeach
                    @foreach ($productPreview->requirements as $requirement)<p class="mt-3 text-sm text-muted">{{ $requirement }}</p>@endforeach
                </x-signal.ui.card>
            @endforeach
            @if ($preview->ready())
                <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.blueprints.apply', [$workspace, $version])" class="space-y-4 p-5">
                    @csrf
                    <x-signal.ui.input type="hidden" name="project_id" :value="$project->getKey()" />
                    <x-signal.ui.input type="hidden" name="preview_token" :value="$previewToken" />
                    <x-signal.ui.input type="hidden" name="idempotency_key" :value="$idempotencyKey" />
                    @foreach ($selections as $key => $selection)<x-signal.ui.input type="hidden" :name="'environments['.$key.']'" :value="$selection ?? ''" />@endforeach
                    <x-signal.ui.checkbox name="confirmed" value="1" required>{{ __('Apply this version and create or configure the resources in this preview.') }}</x-signal.ui.checkbox>
                    <x-signal.ui.button type="submit">{{ __('Apply blueprint') }}</x-signal.ui.button>
                </x-signal.ui.card>
            @endif
        </section>
    @endif
    <x-signal.ui.disclosure :title="__('Definition and new version')" class="mt-6">
        <form method="POST" action="{{ route('core.workspace.blueprints.versions.store', [$workspace, $blueprint]) }}" class="space-y-4">
            @csrf
            <x-signal.ui.input-field name="name" :label="__('Blueprint name')" :value="$blueprint->name" required maxlength="120" />
            <x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$blueprint->description" maxlength="1000" />
            <x-signal.ui.textarea-field name="definition" :label="__('Definition')" :value="$definitionJson" :restore="false" rows="18" required class="font-mono text-xs" />
            <p class="text-xs text-muted">{{ __('Saving creates a new version. Existing versions and applications keep their original definition. Never include secrets or subscription details.') }}</p>
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Save a new version') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.disclosure>
</x-signal.layouts.platform>
