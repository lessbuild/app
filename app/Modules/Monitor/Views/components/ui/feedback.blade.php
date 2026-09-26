@if(session('status'))
    <x-monitor::ui.alert role="status" tone="success" class="text-sm">{{ session('status') }}</x-monitor::ui.alert>
@endif
@if($errors->any())
    <x-monitor::ui.alert role="alert" tone="danger" class="text-sm">
        <p class="font-semibold">Please check the following:</p>
        <ul class="mt-2 list-inside list-disc space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </x-monitor::ui.alert>
@endif
