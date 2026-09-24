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
        <x-signal.ui.input type="hidden" name="_status_page_form" value="1" :restore="false" />
        <h3 class="font-bold text-ink">{{ __('Create status page') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="ui-label">{{ __('Name') }}</span>
                <x-signal.ui.input name="name" value="{{ old('name') }}" placeholder="{{ config('app.name') }} Status" class="ui-input" required :restore="false" />
                <x-forms.errors name="name" />
            </label>
            <label class="block">
                <span class="ui-label">{{ __('Slug') }}</span>
                <x-signal.ui.input name="slug" value="{{ old('slug') }}" placeholder="deployer" class="ui-input" :restore="false" />
                <x-forms.errors name="slug" />
            </label>
            <label class="block sm:col-span-2">
                <span class="ui-label">{{ __('Description') }}</span>
                <x-signal.ui.textarea name="description" placeholder="Current platform availability" class="ui-input" :restore="false">{{ old('description') }}</x-signal.ui.textarea>
                <x-forms.errors name="description" />
            </label>
        </div>
        <fieldset class="grid gap-2 sm:grid-cols-2">
            <legend class="ui-label">{{ __('Components') }}</legend>
            @foreach ($websites as $website)
                <label class="ui-choice">
                    <x-signal.ui.input type="checkbox" name="website_ids[]" value="{{ $website->id }}" class="ui-check" @checked(in_array((string) $website->id, array_map('strval', (array) old('website_ids', [])), true)) :restore="false" />
                    <span class="min-w-0 truncate text-sm text-ink">{{ $website->name }}</span>
                </label>
            @endforeach
            <x-forms.errors name="website_ids" />
        </fieldset>
        <x-signal.ui.input type="hidden" name="is_published" value="1" :restore="false" />
        <x-signal.ui.button type="submit" variant="primary">{{ __('Publish status page') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
