@props(['name', 'bag' => 'default'])

@if ($errors->getBag($bag)->has($name))
    <div class="my-2">
        <x-alerts.info
            :title="$errors->getBag($bag)->first($name)"
        ></x-alerts.info>
    </div>
@endif
