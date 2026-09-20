@props(['name', 'bag' => 'default'])

@if ($errors->getBag($bag)->has($name))
    <div data-form-error class="my-2">
        <x-ui.alert tone="danger" role="alert">
            {{ $errors->getBag($bag)->first($name) }}
        </x-ui.alert>
    </div>
@endif
