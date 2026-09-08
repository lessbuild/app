@if ($errors->any())
    <div id="provider-errors" role="alert" tabindex="-1" autofocus class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">
        <p class="font-semibold">{{ __('Provider could not be saved.') }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
