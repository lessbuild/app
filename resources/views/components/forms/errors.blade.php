@props(['name', 'bag' => 'default', 'id' => null])

@if ($errors->getBag($bag)->has($name))
    <div @if($id) id="{{ $id }}" @endif data-form-error class="my-2" aria-live="polite">
        <x-ui.alert tone="danger" role="alert">
            {{ $errors->getBag($bag)->first($name) }}
        </x-ui.alert>
    </div>
@endif
