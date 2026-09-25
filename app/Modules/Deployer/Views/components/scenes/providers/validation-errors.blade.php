@if ($errors->any())
    <x-signal.ui.alert id="provider-errors" data-form-error-summary role="alert" tabindex="-1" autofocus tone="danger" class="mb-5 border-l-4">
        <div class="min-w-0">
            <p class="font-semibold text-ink">{{ __('Provider could not be saved.') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-muted">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </x-signal.ui.alert>
@endif
