@php($newToken = session('new_token'))
@php($oldScopes = (array) old('scopes', ['account:read']))

<x-signal.layouts.account :account="$account" :title="__('API tokens')" :description="__('Tokens let scripts and CI call the :app API as you, inside :account.', ['app' => config('app.name'), 'account' => $account->name])">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if (is_array($newToken))
        <x-signal.ui.panel as="section" class="space-y-4 border-warning p-6" aria-labelledby="new-token-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Copy it now') }}</p>
                <h2 id="new-token-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Your new token “:name”', ['name' => $newToken['name']]) }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('This is the only time it is shown. Send it as a bearer token: Authorization: Bearer <token>.') }}</p>
            </div>
            <x-signal.ui.code-block :code="$newToken['value']" class="break-all whitespace-pre-wrap" data-new-api-token />
        </x-signal.ui.panel>
    @endif

    <x-slot:actions>
        <x-signal.ui.button :href="route('account.api-tokens', ['dialog' => 'create-api-token'])" variant="primary" data-modal-trigger="create-api-token">{{ __('Create a token') }}</x-signal.ui.button>
    </x-slot:actions>
    <x-signal.overlays.modal id="create-api-token" :title="__('Create a token')" :description="__('Give each script its own token with only the scopes it needs. A token stops working if you leave the account or lose the right to manage tokens.')">
        <form method="POST" action="{{ route('account.api-tokens.store') }}" class="grid gap-5">
            @csrf
            <input type="hidden" name="_modal" value="create-api-token">
            <x-signal.ui.input-field name="name" :label="__('Token name')" :description="__('What uses it, such as “GitHub Actions deploy”.')" maxlength="100" autocomplete="off" required />

            <fieldset class="grid gap-3">
                <legend class="text-sm font-bold text-ink">{{ __('Scopes') }}</legend>
                <p class="text-xs text-muted">{{ __('Read and write includes read.') }}</p>
                <div class="divide-y divide-line rounded-panel border border-line">
                    @foreach (collect($scopes)->groupBy(fn ($scope) => $scope->group()) as $group => $groupScopes)
                        <div class="grid gap-2 px-4 py-3 sm:grid-cols-[9rem_repeat(2,minmax(0,1fr))] sm:items-center">
                            <p class="text-sm font-bold text-ink">{{ $group }}</p>
                            @foreach ($groupScopes as $scope)
                                <x-signal.ui.checkbox :id="'scope-'.str_replace(':', '-', $scope->value)" name="scopes[]" :value="$scope->value" :checked="in_array($scope->value, $oldScopes, true)" :restore="false" :error-key="false" :aria-label="$scope->label()">{{ str_ends_with($scope->value, ':write') ? __('Read and write') : __('Read') }}</x-signal.ui.checkbox>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @error('scopes')
                    <p class="text-sm font-bold text-danger" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>

            <x-signal.ui.select-field name="expires" :label="__('Expires')" required>
                @foreach ($expiryChoices as $choice)
                    <option value="{{ $choice }}" @selected(old('expires', '90') === $choice)>{{ $choice === 'never' ? __('Never') : trans_choice('In :count day|In :count days', (int) $choice, ['count' => $choice]) }}</option>
                @endforeach
            </x-signal.ui.select-field>

            <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Create token') }}</x-signal.ui.button></div>
        </form>
    </x-signal.overlays.modal>

    <x-signal.ui.settings-section :title="__('Command-line tool')" :description="__('Deploy, follow logs and roll back from your terminal or CI with a token that has the Deploy scopes.')">
        <div class="grid gap-3 p-4 sm:p-6">
            <x-signal.ui.code-block :code="'curl -fsSL '.route('cli.install').' | sh'" :aria-label="__('Install command')" />
            <p class="text-sm text-muted">{{ __('Then run buildpusher login and paste a token.') }} <a href="{{ route('help.guide', 'use-the-cli') }}" class="font-bold text-primary underline">{{ __('How to use it') }}</a></p>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Tokens in this account')" :description="__('Everyone’s tokens for :account. Revoke any you don’t recognise.', ['account' => $account->name])">
        @if ($tokens === [])
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No tokens yet.') }}</p>
        @else
            <ul class="divide-y divide-line" aria-label="{{ __('API tokens') }}">
                @foreach ($tokens as $token)
                    <li class="flex flex-wrap items-start justify-between gap-4 p-4 sm:px-6">
                        <div class="min-w-0">
                            <p class="break-words text-sm font-bold text-ink">{{ $token->name }}</p>
                            <p class="mt-1 text-xs leading-5 text-muted">
                                {{ $token->ownedByViewer ? __('Yours') : $token->owner }}
                                · {{ __('Last used :time', ['time' => $token->lastUsedAt?->diffForHumans() ?? __('never')]) }}
                                · @if ($token->expiresAt === null) {{ __('Never expires') }} @elseif ($token->expiresAt->isPast()) <span class="font-bold text-danger">{{ __('Expired') }}</span> @else {{ __('Expires :time', ['time' => $token->expiresAt->diffForHumans()]) }} @endif
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($token->scopes as $scope)
                                    <x-signal.ui.badge>{{ $scope->value }}</x-signal.ui.badge>
                                @endforeach
                            </div>
                        </div>
                        <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="revoke-token-{{ $token->id }}">{{ __('Revoke') }}</x-signal.ui.button>
                        <x-signal.overlays.delete-confirmation
                            :id="'revoke-token-'.$token->id"
                            :route="route('account.api-tokens.destroy', $token->id)"
                            :title="__('Revoke “:name”?', ['name' => $token->name])"
                            :description="__('Anything using this token stops working straight away.')"
                            :submit-label="__('Revoke')"
                        />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
