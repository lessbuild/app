@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Access')" :description="__('Everyone and everything that can reach this account. Review it every :days days: tick anything that should go, then complete the review. Each review is kept as evidence.', ['days' => \App\Models\SecurityAccessReview::DUE_AFTER_DAYS])">
    @unless ($included)
        <x-signal.ui.alert tone="info">{{ __('Access reviews come with the Team Security plan.') }} <a class="ui-link" href="{{ route('account.billing', ['tab' => 'security']) }}">{{ __('See plans') }}</a></x-signal.ui.alert>
    @endunless

    <form method="POST" action="{{ route('security.access.store', $project) }}" class="grid gap-4">
        @csrf
        <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="access-members">
            <h2 id="access-members" class="text-lg font-extrabold text-ink">{{ __('Members') }}</h2>
            <x-signal.ui.table :caption="__('Members')" :framed="false">
                <x-slot:head><tr><th scope="col">{{ __('Person') }}</th><th scope="col">{{ __('Role') }}</th><th scope="col">{{ __('Two-factor') }}</th><th scope="col">{{ __('Projects') }}</th><th scope="col" class="text-right">{{ __('Remove') }}</th></tr></x-slot:head>
                @foreach ($members as $membership)
                    <tr>
                        <td><span class="font-bold text-ink">{{ $membership->user->name }}</span> <span class="block text-xs text-muted">{{ $membership->user->email }}</span></td>
                        <td>{{ $membership->role->label() }}</td>
                        <td>@if ($membership->user->two_factor_confirmed_at)<x-signal.ui.badge tone="success">{{ __('On') }}</x-signal.ui.badge>@else<x-signal.ui.badge :tone="$requireTwoFactor ? 'neutral' : 'warning'">{{ __('Off') }}</x-signal.ui.badge>@endif</td>
                        <td class="text-sm text-muted">{{ $membership->project_ids === null ? __('All') : count($membership->project_ids) }}</td>
                        <td class="text-right">@if ($canManage && $included && $membership->user_id !== auth()->id())<input type="checkbox" name="members[]" value="{{ $membership->id }}" aria-label="{{ __('Remove :name', ['name' => $membership->user->name]) }}">@endif</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        </x-signal.ui.card>

        <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="access-tokens">
            <h2 id="access-tokens" class="text-lg font-extrabold text-ink">{{ __('API tokens') }}</h2>
            @if ($tokens->isEmpty())
                <p class="mt-2 text-sm text-muted">{{ __('No API tokens.') }}</p>
            @else
                <x-signal.ui.table :caption="__('API tokens')" :framed="false">
                    <x-slot:head><tr><th scope="col">{{ __('Token') }}</th><th scope="col">{{ __('Owner') }}</th><th scope="col">{{ __('Last used') }}</th><th scope="col">{{ __('Expires') }}</th><th scope="col" class="text-right">{{ __('Revoke') }}</th></tr></x-slot:head>
                    @foreach ($tokens as $token)
                        <tr>
                            <td><span class="font-bold text-ink">{{ $token->name }}</span> <span class="block text-xs text-muted">{{ implode(', ', $token->abilities) }}</span></td>
                            <td class="text-sm">{{ $tokenOwners[$token->tokenable_id] ?? '—' }}</td>
                            <td class="text-sm text-muted">{{ $token->last_used_at?->diffForHumans() ?? __('Never') }}</td>
                            <td class="text-sm text-muted">{{ $token->expires_at?->toFormattedDateString() ?? __('Never') }}</td>
                            <td class="text-right">@if ($canManage && $included)<input type="checkbox" name="tokens[]" value="{{ $token->id }}" aria-label="{{ __('Revoke :name', ['name' => $token->name]) }}">@endif</td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
            @endif
        </x-signal.ui.card>

        <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="access-ssh">
            <h2 id="access-ssh" class="text-lg font-extrabold text-ink">{{ __('SSH access') }}</h2>
            @if ($grants->isEmpty())
                <p class="mt-2 text-sm text-muted">{{ __('No one has personal SSH access to this project’s servers.') }}</p>
            @else
                <ul class="mt-2 divide-y divide-line">
                    @foreach ($grants as $grant)
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <span><span class="font-bold text-ink">{{ $grant->user->name }}</span> <span class="text-muted">· {{ $grant->server->display_name ?: $grant->server->name }}</span></span>
                            @if ($canManage && $included)<input type="checkbox" name="grants[]" value="{{ $grant->id }}" aria-label="{{ __('Remove :name’s access to :server', ['name' => $grant->user->name, 'server' => $grant->server->name]) }}">@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-signal.ui.card>

        @if ($canManage && $included)
            <div><x-signal.ui.button type="submit" variant="primary">{{ __('Complete review') }}</x-signal.ui.button></div>
        @endif
    </form>

    @if ($reviews->isNotEmpty())
        <x-signal.ui.card as="section" class="p-5 sm:p-6" aria-labelledby="past-reviews">
            <h2 id="past-reviews" class="text-lg font-extrabold text-ink">{{ __('Past reviews') }}</h2>
            <ul class="mt-2 grid gap-2 text-sm">
                @foreach ($reviews as $review)
                    <li>
                        <span class="font-bold text-ink">{{ $review->created_at?->toFormattedDateString() }}</span>
                        <span class="text-muted">· {{ $review->reviewer->name ?? __('Someone') }} · {{ trans_choice(':count member|:count members', $review->summary['members'], ['count' => $review->summary['members']]) }}, {{ trans_choice(':count token|:count tokens', $review->summary['tokens'], ['count' => $review->summary['tokens']]) }}@if ($review->summary['removed'] !== []) · {{ __('removed :items', ['items' => implode(', ', $review->summary['removed'])]) }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
