@props([
    'website',
    'servers',
    'cancelUrl' => null,
    'fieldPrefix' => 'website-edit-',
])

<form action="{{ route('websites.update', ['website' => $website, 'dialog' => 'edit-website']) }}" method="POST">
    @csrf
    @method('PATCH')
    <x-scenes.websites._form
        :servers="$servers"
        :website="$website"
        :field-prefix="$fieldPrefix"
    />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
        <x-ui.button :href="$cancelUrl ?? route('websites.show', $website)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary">{{ __('Save Website') }}</x-ui.button>
    </div>
</form>
