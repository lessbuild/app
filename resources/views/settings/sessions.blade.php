@php($status = session('status'))
@php($statusMessages = [
    'browser-signed-out' => __('That browser is signed out.'),
    'browser-not-found' => __('That browser was already signed out.'),
    'other-browsers-signed-out' => __('All other browsers are signed out.'),
])

<x-signal.layouts.settings :title="__('Sessions')" :description="__('Where you are signed in, and recent sign-ins to your account.')">
    @if (is_string($status) && isset($statusMessages[$status]))
        <x-signal.ui.alert tone="success" role="status">{{ $statusMessages[$status] }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.settings-section :title="__('Signed-in browsers')" :description="__('Sign out any browser you don’t recognise. This also forgets “Remember me” on your other browsers, so they’ll ask you to sign in next time.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @if ($sessions === null)
                <p class="text-sm text-muted">{{ __('Browser sessions can’t be listed on this server.') }}</p>
            @else
                <ul class="grid gap-3" aria-label="{{ __('Signed-in browsers') }}">
                    @foreach ($sessions as $session)
                        <li class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-line p-4">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-ink">{{ $session->device }}</p>
                                <p class="mt-1 text-xs leading-5 text-muted">
                                    {{ $session->ipAddress ?? __('Unknown IP address') }}
                                    · {{ $session->current ? __('Active now') : __('Last active :time', ['time' => $session->lastActiveAt->diffForHumans()]) }}
                                </p>
                            </div>
                            @if ($session->current)
                                <x-signal.ui.badge tone="success">{{ __('This browser') }}</x-signal.ui.badge>
                            @else
                                <form method="POST" action="{{ route('settings.sessions.destroy', $session->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-signal.ui.button type="submit" variant="quiet" :aria-label="__('Sign out :device', ['device' => $session->device])">{{ __('Sign out') }}</x-signal.ui.button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (count($sessions) > 1)
                    <form method="POST" action="{{ route('settings.sessions.destroy-others') }}">
                        @csrf
                        @method('DELETE')
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Sign out all other browsers') }}</x-signal.ui.button>
                    </form>
                @endif
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Sign-in history')" :description="__('Successful and failed sign-ins from the last :days days. If you don’t recognise one, change your password and sign out other browsers.', ['days' => $retentionDays])">
        @if ($signIns === [])
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No sign-ins recorded yet.') }}</p>
        @else
            <x-signal.ui.table :caption="__('Recent sign-ins')" :framed="false">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('When') }}</th>
                        <th scope="col">{{ __('Result') }}</th>
                        <th scope="col">{{ __('Method') }}</th>
                        <th scope="col">{{ __('Device') }}</th>
                    </tr>
                </x-slot:head>
                @foreach ($signIns as $signIn)
                    <tr>
                        <td class="whitespace-nowrap"><time datetime="{{ $signIn->at->toIso8601String() }}" title="{{ $signIn->at->toDayDateTimeString() }}">{{ $signIn->at->diffForHumans() }}</time></td>
                        <td><x-signal.ui.badge :tone="$signIn->succeeded ? 'success' : 'danger'">{{ $signIn->succeeded ? __('Signed in') : __('Failed') }}</x-signal.ui.badge></td>
                        <td>{{ $signIn->method?->label() ?? __('Unknown') }}@if ($signIn->twoFactor) {{ __('+ authenticator') }}@endif</td>
                        <td>
                            <span class="block">{{ $signIn->device }}</span>
                            <span class="block text-xs text-muted">{{ $signIn->ipAddress ?? __('Unknown IP address') }}</span>
                        </td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.settings>
