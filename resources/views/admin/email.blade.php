<x-signal.layouts.admin :title="__('Email')" :description="__('How email is sent, what would stop it arriving, and a test you can send. Secrets are never shown.')">
    @if (session('error'))<x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>@endif

    @forelse ($problems as $problem)
        <x-signal.ui.alert tone="danger" role="alert">{{ $problem }}</x-signal.ui.alert>
    @empty
        <x-signal.ui.alert tone="success" role="status">{{ __('Email is set up to be delivered. Send a test to be sure.') }}</x-signal.ui.alert>
    @endforelse

    <x-signal.ui.table :caption="__('Settings')">
        <x-slot:head><tr><th scope="col">{{ __('Setting') }}</th><th scope="col">{{ __('Value') }}</th></tr></x-slot:head>
        @foreach ($settings as [$label, $value])
            <tr><td>{{ __($label) }}</td><td class="font-mono text-xs">{{ $value }}</td></tr>
        @endforeach
    </x-signal.ui.table>

    <x-signal.ui.settings-section :title="__('Send a test email')" :description="__('Sent straight away, not queued, so any error shows here.')">
        <form method="POST" action="{{ route('admin.email.test') }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-end sm:p-6">
            @csrf
            <div class="flex-1"><x-signal.ui.input-field id="test-to" name="to" type="email" :label="__('Send to')" :value="auth()->user()?->email" required /></div>
            <x-signal.ui.button type="submit" variant="primary">{{ __('Send test') }}</x-signal.ui.button>
        </form>
        @if ($lastTest)
            <p class="border-t border-line px-4 py-3 text-sm text-muted sm:px-6">
                {{ __('Last test: :when to :to with :mailer —', ['when' => \Illuminate\Support\Carbon::parse($lastTest['at'])->diffForHumans(), 'to' => $lastTest['to'], 'mailer' => $lastTest['mailer']]) }}
                <span @class(['font-semibold', 'text-success' => $lastTest['sent'], 'text-danger' => ! $lastTest['sent']])>{{ $lastTest['sent'] ? __('sent') : __('failed: :error', ['error' => $lastTest['error']]) }}</span>
            </p>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.admin>
