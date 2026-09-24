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

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-signal.ui.button :href="$cancelUrl ?? route('websites.show', $website)" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save Website') }}</x-signal.ui.button>
    </div>
</form>
