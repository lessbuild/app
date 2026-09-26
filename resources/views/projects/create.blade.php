<x-signal.layouts.app :title="__('New project')">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="__('New project')" :description="__('One project per app or site. It starts with a Production environment; you can add staging and others later.')" :breadcrumbs="[['label' => __('Projects'), 'href' => route('dashboard')], ['label' => __('New project')]]" />

    <x-signal.ui.card class="max-w-2xl p-4 sm:p-6">
        <form method="POST" action="{{ route('projects.store') }}" class="grid gap-5">
            @csrf
            <x-signal.ui.input-field name="name" :label="__('Project name')" maxlength="100" autocomplete="off" required autofocus />
            <x-signal.ui.textarea-field name="description" :label="__('Description')" :description="__('Optional. What this project is, for your teammates.')" maxlength="500" rows="3" />
            <div class="flex flex-wrap gap-3">
                <x-signal.ui.button type="submit" variant="primary">{{ __('Create project') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('dashboard')" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
            </div>
        </form>
    </x-signal.ui.card>
</x-signal.layouts.app>
