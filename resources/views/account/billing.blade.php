@php
    $money = fn (?int $cents): string => $cents === null ? __('Price to be set') : '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    $statusLabels = ['none' => __('Free'), 'active' => __('Active'), 'trialing' => __('Trial'), 'past_due' => __('Payment overdue'), 'unpaid' => __('Unpaid'), 'canceled' => __('Cancelled'), 'incomplete' => __('Waiting for payment')];
@endphp

<x-signal.layouts.account :account="$account" :title="__('Billing')" :description="__('Pick a plan for each service. Only the service you change is affected, and changes are prorated.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if ($checkout === 'done')
        <x-signal.ui.alert tone="success" role="status">{{ __('Thanks! Your plan starts as soon as the payment is confirmed; this page updates in a moment.') }}</x-signal.ui.alert>
    @elseif ($checkout === 'cancelled')
        <x-signal.ui.alert tone="info" role="status">{{ __('Checkout was cancelled. Nothing changed.') }}</x-signal.ui.alert>
    @endif
    @foreach (['tier', 'portal'] as $field)
        @error($field)
            <x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>
        @enderror
    @endforeach
    @unless ($overview->paymentsAvailable)
        <x-signal.ui.alert tone="warning" role="status">{{ __('Payments aren’t connected in this environment, so only free plans can be chosen.') }}</x-signal.ui.alert>
    @endunless
    @if ($overview->trialDays > 0 && $overview->paymentsAvailable)
        <x-signal.ui.alert tone="info" role="status">{{ __('Your first paid plan starts with a :days-day free trial. You won’t be charged until it ends, and you can cancel before then.', ['days' => $overview->trialDays]) }}</x-signal.ui.alert>
    @endif
    @if ($overview->status === 'past_due' || $overview->status === 'unpaid')
        <x-signal.ui.alert tone="danger" role="alert">{{ __('Your last payment didn’t go through. Update your payment method to keep your plans.') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.page-tabs :tabs="$tabs" :current="$tab" :url="route('account.billing')" :label="__('Billing sections')" />

    <x-signal.ui.page-tab-panel name="overview" :current="$tab">
        <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
            <div>
                <p class="font-bold text-ink">{{ $interval === 'year' ? __('You pay yearly') : __('You pay monthly') }}</p>
                <p class="text-sm text-muted">{{ __('Yearly costs ten months, so two months are free. Switching moves every paid plan and is prorated.') }}</p>
            </div>
            @if ($canManage)
                <form method="POST" action="{{ route('account.billing.interval') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="interval" value="{{ $interval === 'year' ? 'month' : 'year' }}">
                    <x-signal.ui.button type="submit" variant="secondary">{{ $interval === 'year' ? __('Pay monthly instead') : __('Pay yearly, 2 months free') }}</x-signal.ui.button>
                </form>
            @endif
        </x-signal.ui.card>

        <x-signal.ui.card class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-6">
            <div class="grid gap-1">
                <p class="ui-eyebrow">{{ __('Monthly total') }}</p>
                <p class="text-3xl font-extrabold tracking-tight text-ink">{{ $money($overview->monthlyTotalCents) }}<span class="text-sm font-bold text-muted"> / {{ __('month') }}</span></p>
                <p class="text-sm text-muted">
                    {{ $statusLabels[$overview->status] ?? $overview->status }}
                    @if ($overview->periodEnd)
                        · {{ __('Renews :date', ['date' => $overview->periodEnd->toFormattedDateString()]) }}
                    @endif
                    · {{ __('Prices exclude tax.') }}
                </p>
            </div>
            @if ($canManage && $overview->hasCustomer)
                <form method="POST" action="{{ route('account.billing.portal') }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Payment method and billing details') }}</x-signal.ui.button>
                </form>
            @endif
        </x-signal.ui.card>

        @if ($overview->limits !== [])
            <x-signal.ui.settings-section :title="__('Plan limits')" :description="__('What you’re using of each limit your plans set. Monthly limits reset on the 1st.')">
                <ul class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6">
                    @foreach ($overview->limits as $limit)
                        <li class="grid gap-2">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="font-bold text-ink">{{ ucfirst($limit->label) }}@if ($limit->monthly) <span class="font-normal text-muted">{{ __('this month') }}</span>@endif</span>
                                <span @class(['font-semibold', 'text-danger' => $limit->percent() >= 100, 'text-warning' => $limit->nearLimit() && $limit->percent() < 100, 'text-muted' => ! $limit->nearLimit()])>{{ number_format($limit->used) }} / {{ number_format((int) $limit->limit) }}</span>
                            </div>
                            <x-signal.ui.progress :value="min($limit->used, (int) $limit->limit)" :max="(int) $limit->limit" :label="__(':label used', ['label' => $limit->label])" />
                            @if ($limit->nearLimit() && $limit->service !== 'account')
                                @php($upgrade = app(\App\Services\Billing\PlanUsage::class)->upgradeFor($limit))
                                <a href="#billing-{{ $limit->service }}" class="text-xs font-semibold text-primary hover:underline">
                                    {{ $limit->percent() >= 100 ? __('Limit reached.') : __('Nearly there.') }}
                                    {{ $upgrade ? __(':tier gives :limit for $:price/mo', ['tier' => $upgrade['tier']->name, 'limit' => $upgrade['limit'] === null ? __('unlimited') : number_format($upgrade['limit']), 'price' => number_format($upgrade['monthlyCents'] / 100, $upgrade['monthlyCents'] % 100 === 0 ? 0 : 2)]) : __('See plans with more') }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.settings-section>
        @endif
    </x-signal.ui.page-tab-panel>

    @foreach ($overview->services as $service)
        <x-signal.ui.page-tab-panel :name="$service->key" :current="$tab">
            <x-signal.ui.card as="section" class="grid gap-5 p-5 sm:p-6" :aria-labelledby="'billing-'.$service->key">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)]" aria-hidden="true">
                            <svg class="h-5 w-5 stroke-2"><use xlink:href="/assets/images/icons.svg#{{ $service->icon }}"></use></svg>
                        </span>
                        <div>
                            <h2 id="billing-{{ $service->key }}" class="text-lg font-extrabold text-ink">{{ $service->name }}</h2>
                            <p class="text-sm text-muted">
                                {{ $service->tier->name }}@if (($service->tier->monthlyCents ?? 0) > 0) · {{ $interval === 'year' ? $money($service->tier->yearlyCents()).' / '.__('year') : $money($service->tier->monthlyCents).' / '.__('month') }}@endif
                                @unless ($service->inUse) · {{ __('not used by any project yet') }} @endunless
                            </p>
                        </div>
                    </div>
                    @if ($service->endsAt)
                        <div class="flex flex-wrap items-center gap-2">
                            <x-signal.ui.badge tone="warning">{{ __('Moves to free on :date', ['date' => $service->endsAt->toFormattedDateString()]) }}</x-signal.ui.badge>
                            @if ($canManage)
                                <form method="POST" action="{{ route('account.billing.resume', $service->key) }}">
                                    @csrf
                                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Keep :tier', ['tier' => $service->tier->name]) }}</x-signal.ui.button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>

                @foreach ($service->meters as $meter)
                    <div class="grid gap-1.5">
                        <div class="flex flex-wrap justify-between gap-2 text-sm">
                            <span class="font-bold text-ink">{{ __(':meter this month', ['meter' => $meter->name]) }}</span>
                            <span class="text-muted">{{ number_format($meter->used) }} / {{ $meter->allowance === null ? __('unlimited') : number_format($meter->allowance) }} {{ $meter->unit }}</span>
                        </div>
                        @if ($meter->allowance)
                            <x-signal.ui.progress :value="min($meter->used, $meter->allowance)" :max="$meter->allowance" :label="__(':meter used this month', ['meter' => $meter->name])" />
                        @endif
                        @if ($meter->unitCents > 0 && $meter->allowance !== null && ($meter->payAsYouGo || $meter->payAsYouGoAvailable || $canManage))
                            <div class="mt-2 grid gap-2 rounded-control border border-line p-3 text-sm" id="pay-as-you-go-{{ $service->key }}">
                                <p class="font-bold text-ink">{{ __('Pay as you go') }}
                                    @if ($meter->payAsYouGo)<x-signal.ui.badge tone="success">{{ __('On') }}</x-signal.ui.badge>@else<x-signal.ui.badge>{{ __('Off') }}</x-signal.ui.badge>@endif
                                </p>
                                <p class="text-muted">
                                    {{ __('Past your allowance, :price per :size :unit instead of stopping.', ['price' => $money($meter->unitCents), 'size' => number_format($meter->unitSize), 'unit' => $meter->unit]) }}
                                    @if ($meter->payAsYouGo)
                                        {{ __('So far this month: :cost.', ['cost' => $money($meter->overageCents)]) }}
                                        {{ $meter->spendCapCents === null ? __('No spend cap.') : __('Stops at :cap a month.', ['cap' => $money($meter->spendCapCents)]) }}
                                    @endif
                                </p>
                                @if ($canManage && ($meter->payAsYouGo || $meter->payAsYouGoAvailable))
                                    <form method="POST" action="{{ route('account.billing.usage', $service->key) }}" class="flex flex-wrap items-end gap-3">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="meter" value="{{ $meter->key }}">
                                        <input type="hidden" name="enabled" value="{{ $meter->payAsYouGo ? '0' : '1' }}">
                                        @unless ($meter->payAsYouGo)
                                            <x-signal.ui.input-field :id="'usage-cap-'.$service->key" name="cap" type="number" min="1" max="100000" :label="__('Monthly spend cap in dollars (optional)')" />
                                        @endunless
                                        <x-signal.ui.button type="submit" :variant="$meter->payAsYouGo ? 'quiet' : 'secondary'" size="sm">{{ $meter->payAsYouGo ? __('Turn off') : __('Turn on') }}</x-signal.ui.button>
                                    </form>
                                @elseif ($canManage)
                                    <p class="text-xs text-muted">{{ __('Available on a paid monthly plan once usage pricing is set up.') }}</p>
                                @endif
                                @error('usage')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                @endforeach

                @if (count($service->options) > 1 || ! $canManage)
                    <form method="POST" action="{{ route('account.billing.change', $service->key) }}" class="grid gap-4">
                        @csrf
                        <fieldset class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" @disabled(! $canManage)>
                            <legend class="sr-only">{{ __(':service plans', ['service' => $service->name]) }}</legend>
                            @foreach ($service->options as $option)
                                <label @class(['ui-choice flex h-full cursor-pointer flex-col gap-2 rounded-panel border p-4', 'border-primary bg-primary-soft/40' => $option->current, 'border-line' => ! $option->current, 'cursor-not-allowed opacity-60' => ! $option->purchasable])>
                                    <span class="flex items-start justify-between gap-2">
                                        <span class="flex items-center gap-2">
                                            <input type="radio" name="tier" value="{{ $option->tier->key }}" class="ui-check" @checked($option->current) @disabled(! $option->purchasable && ! $option->current)>
                                            <span class="font-extrabold text-ink">{{ $option->tier->name }}</span>
                                        </span>
                                        <span class="text-sm font-bold text-ink">{{ $money($interval === 'year' ? $option->tier->yearlyCents() : $option->tier->monthlyCents) }}@if (($option->tier->monthlyCents ?? 0) > 0)<span class="font-normal text-muted">/{{ $interval === 'year' ? __('yr') : __('mo') }}</span>@endif</span>
                                    </span>
                                    <span class="text-xs text-muted">{{ $option->tier->description }}</span>
                                    @if ($option->tier->features !== [])
                                        <ul class="mt-1 grid gap-1 text-xs text-ink">
                                            @foreach ($option->tier->features as $feature)
                                                <li class="flex gap-1.5"><span class="text-[var(--ui-success)]" aria-hidden="true">✓</span>{{ $feature }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if ($option->current)
                                        <x-signal.ui.badge tone="accent" class="mt-auto w-fit">{{ __('Current plan') }}</x-signal.ui.badge>
                                    @elseif (! $option->purchasable)
                                        <span class="mt-auto text-xs font-bold text-muted">{{ __('Not on sale yet') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </fieldset>
                        @if ($canManage && count($service->options) > 1)
                            <div><x-signal.ui.button type="submit" variant="primary">{{ __('Switch :service plan', ['service' => $service->name]) }}</x-signal.ui.button></div>
                        @endif
                    </form>
                @endif
            </x-signal.ui.card>
        </x-signal.ui.page-tab-panel>
    @endforeach

    <x-signal.ui.page-tab-panel name="invoices" :current="$tab">
        @php($money = fn (int $cents): string => '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2))
        <x-signal.ui.settings-section id="refer" :title="__('Refer a friend')" :description="__('Share your link. When an account that signs up through it starts paying, you both get :amount of credit off your next invoices.', ['amount' => $money($referrals['credit_cents'])])">
            <div class="grid gap-4 p-4 sm:p-6">
                <x-signal.ui.input-field name="referral_link" :label="__('Your link')" :value="$referrals['link']" readonly :restore="false" />
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-muted">{{ __('Signed up') }}</dt><dd class="text-lg font-extrabold text-ink">{{ number_format($referrals['signed_up']) }}</dd></div>
                    <div><dt class="text-muted">{{ __('Started paying') }}</dt><dd class="text-lg font-extrabold text-ink">{{ number_format($referrals['qualified']) }}</dd></div>
                    <div><dt class="text-muted">{{ __('Credit earned') }}</dt><dd class="text-lg font-extrabold text-ink">{{ $money($referrals['earned_cents']) }}</dd>
                        @if ($referrals['pending_cents'] > 0)<dd class="text-xs text-muted">{{ __(':amount more once you have a subscription', ['amount' => $money($referrals['pending_cents'])]) }}</dd>@endif
                    </div>
                </dl>
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Invoices')" :description="__('Receipts for past payments.')">
            @if ($invoices === null)
                <p class="p-4 text-sm text-muted sm:p-6">{{ __('Invoices can’t be loaded right now. Try again in a moment.') }}</p>
            @elseif ($invoices === [])
                <p class="p-4 text-sm text-muted sm:p-6">{{ __('No invoices yet.') }}</p>
            @else
                <x-signal.ui.table :caption="__('Invoices')" :framed="false">
                    <x-slot:head>
                        <tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Invoice') }}</th><th scope="col">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th></tr>
                    </x-slot:head>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->date->toFormattedDateString() }}</td>
                            <td>@if ($invoice->url)<a class="ui-link" href="{{ $invoice->url }}" rel="noopener" target="_blank">{{ $invoice->number }}</a>@else{{ $invoice->number }}@endif</td>
                            <td>{{ strtoupper($invoice->currency) }} {{ number_format($invoice->totalCents / 100, 2) }}</td>
                            <td><x-signal.ui.badge :tone="$invoice->status === 'paid' ? 'success' : 'neutral'">{{ ucfirst($invoice->status) }}</x-signal.ui.badge></td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
            @endif
        </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>
</x-signal.layouts.account>
