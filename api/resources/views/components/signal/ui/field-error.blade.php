@props(['name', 'bag' => 'default', 'id' => null])

@if ($errors->getBag($bag)->has($name))
    <div @if($id) id="{{ $id }}" @endif data-form-error class="my-2" aria-live="polite">
        <x-signal.ui.alert tone="danger" role="alert">
            {{ $errors->getBag($bag)->first($name) }}
        </x-signal.ui.alert>
    </div>
@endif
