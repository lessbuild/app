@props([
    'websites',
    'open' => false,
])

<x-dialogs.modal
    id="status-page-create-dialog"
    :title="__('Create status page')"
    :description="__('Publish live component health and rolling 30-day uptime without exposing infrastructure details.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.status-pages.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_status_page_form" value="1">
        <h3 class="font-bold text-primary">{{ __('Create status page') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Name') }}</span>
                <input name="name" value="{{ old('name') }}" placeholder="BuildPusher Status" class="input secondary w-full rounded-md" required>
                <x-forms.errors name="name" />
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Slug') }}</span>
                <input name="slug" value="{{ old('slug') }}" placeholder="buildpusher" class="input secondary w-full rounded-md">
                <x-forms.errors name="slug" />
            </label>
            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Description') }}</span>
                <textarea name="description" placeholder="Current platform availability" class="input secondary w-full rounded-md">{{ old('description') }}</textarea>
                <x-forms.errors name="description" />
            </label>
        </div>
        <fieldset class="grid gap-2 sm:grid-cols-2">
            <legend class="mb-1 text-xs font-bold uppercase text-secondary">{{ __('Components') }}</legend>
            @foreach ($websites as $website)
                <label class="flex items-center gap-2 rounded-lg border border-primary p-3">
                    <input type="checkbox" name="website_ids[]" value="{{ $website->id }}" @checked(in_array((string) $website->id, array_map('strval', (array) old('website_ids', [])), true))>
                    <span class="min-w-0 truncate text-sm text-primary">{{ $website->name }}</span>
                </label>
            @endforeach
            <x-forms.errors name="website_ids" />
        </fieldset>
        <input type="hidden" name="is_published" value="1">
        <x-ui.button type="submit" variant="primary">{{ __('Publish status page') }}</x-ui.button>
    </form>
</x-dialogs.modal>
