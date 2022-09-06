@if ($errors->has($name))
    <div class="my-2">
        <x-alerts.info
            :title="$errors->first($name)"
        ></x-alerts.info>
    </div>
@endif
