{{-- The Queues page: the queues, then the failed jobs. --}}
<x-filament-panels::page>
    @include('filament.pages._queues')
    {{ $this->table }}
</x-filament-panels::page>
