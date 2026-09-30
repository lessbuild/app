@php($project = $overview->project)
@php($tabs = ['insights' => __('Insights'), 'paths' => __('Paths'), 'properties' => __('Properties'), 'items' => __('Items'), 'attribution' => __('Attribution'), 'clicks' => __('Clicks'), 'forms' => __('Forms'), 'retention' => __('Retention')])
@php($here = fn (array $params = []): string => route('analytics.explore', [$project, 'site' => $site?->id, 'tab' => $tab, ...($period?->query() ?? []), ...$params]))
@php($money = fn (float $amount, string $currency): string => $currency.' '.number_format($amount, 2))

<x-signal.layouts.project :overview="$overview" :title="__('Explore')" :description="__('What changed, how people move through the site, what they buy and whether they come back.')">
    @if ($site === null)
        <x-signal.ui.empty-state icon="view-grid" :title="__('Add a site first')" :description="__('Explore reports belong to a site.')" />
    @else
        @include('analytics._site-picker')

        <nav aria-label="{{ __('Explore') }}" class="flex flex-wrap gap-2">
            @foreach ($tabs as $key => $label)
                <x-signal.ui.button :href="route('analytics.explore', [$project, 'site' => $site->id, 'tab' => $key, ...$period->query()])" :variant="$tab === $key ? 'soft' : 'quiet'" size="sm" :aria-current="$tab === $key ? 'page' : null">{{ $label }}</x-signal.ui.button>
            @endforeach
        </nav>

        @if ($tab !== 'retention')
            <x-signal.ui.card class="p-4 sm:p-5">
                <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                    <input type="hidden" name="site" value="{{ $site->id }}">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    @include('analytics._period-fields')
                    @if ($tab === 'paths' || $tab === 'clicks')
                        <x-signal.ui.input-field name="path" :label="__('Page')" :value="$path" placeholder="/pricing" :restore="false" :show-errors="false" />
                    @elseif ($tab === 'attribution')
                        <x-signal.ui.select-field name="by" :label="__('By')" :show-errors="false">
                            <option value="channel" @selected($by === 'channel')>{{ __('Channel') }}</option>
                            <option value="campaign" @selected($by === 'campaign')>{{ __('Campaign') }}</option>
                        </x-signal.ui.select-field>
                    @elseif ($tab === 'properties')
                        <x-signal.ui.select-field name="event" :label="__('Event')" :show-errors="false">
                            <option value="">{{ __('Choose an event') }}</option>
                            @foreach ($result['events'] as $option)
                                <option value="{{ $option['label'] }}" @selected($event === $option['label'])>{{ $option['label'] }} ({{ number_format($option['value']) }})</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.select-field name="property" :label="__('Property')" :show-errors="false">
                            <option value="">{{ __('Choose a property') }}</option>
                            @foreach ($site->custom_properties ?? [] as $option)
                                <option value="{{ $option }}" @selected($property === $option)>{{ $option }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                    @endif
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Show') }}</x-signal.ui.button>
                    </div>
                </form>
            </x-signal.ui.card>
        @endif

        @if ($tab === 'insights')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="insights-heading">
                <div>
                    <h2 id="insights-heading" class="text-lg font-extrabold text-ink">{{ __('What changed') }}</h2>
                    <p class="text-sm text-muted">{{ __('The biggest moves against :comparison: at least half again, or half as much, on ten or more visits or pageviews.', ['comparison' => $period->compare === 'year' ? __('the same period last year') : __('the period before')]) }}</p>
                </div>
                @forelse ($result as $insight)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-line p-3 text-sm">
                        <span class="flex min-w-0 items-center gap-2">
                            <x-signal.ui.badge :tone="$insight['tone']">{{ $insight['change'] === 'New' ? __('New') : $insight['change'] }}</x-signal.ui.badge>
                            <span class="text-muted">{{ __($insight['dimension']) }}</span>
                            <span class="truncate font-bold text-ink">{{ $insight['label'] }}</span>
                        </span>
                        <span class="tabular-nums text-muted">{{ number_format($insight['previous']) }} → <strong class="text-ink">{{ number_format($insight['current']) }}</strong></span>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('Nothing changed much. Insights appear once there’s enough traffic to compare.') }}</p>
                @endforelse
            </x-signal.ui.card>
        @elseif ($tab === 'paths')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="paths-heading">
                <h2 id="paths-heading" class="text-lg font-extrabold text-ink">{{ $path ? __('Around :path', ['path' => $path]) : __('Choose a page to explore') }}</h2>
                @if ($path === null)
                    <ul class="grid gap-2 text-sm">
                        @foreach ($result['starts'] as $row)
                            <li class="flex justify-between gap-4"><a class="truncate text-primary hover:underline" href="{{ $here(['path' => $row['label']]) }}">{{ $row['label'] }}</a><strong class="tabular-nums text-ink">{{ number_format($row['value']) }}</strong></li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-muted">{{ trans_choice(':count view in this period. Click a page to follow the path from there.|:count views in this period. Click a page to follow the path from there.', $result['views'], ['count' => number_format($result['views'])]) }}</p>
                    <div class="grid gap-6 md:grid-cols-2">
                        @foreach (['previous' => __('Came from'), 'next' => __('Went to')] as $key => $title)
                            <div>
                                <h3 class="text-sm font-extrabold text-ink">{{ $title }}</h3>
                                <ul class="mt-2 grid gap-2 text-sm">
                                    @forelse ($result[$key] as $row)
                                        <li class="flex justify-between gap-4">
                                            @if (str_starts_with($row['label'], '('))
                                                <span class="text-muted">{{ $row['label'] === '(exit)' ? __('Left the site') : __('Arrived here') }}</span>
                                            @else
                                                <a class="truncate text-primary hover:underline" href="{{ $here(['path' => $row['label']]) }}">{{ $row['label'] }}</a>
                                            @endif
                                            <strong class="tabular-nums text-ink">{{ number_format($row['value']) }}</strong>
                                        </li>
                                    @empty
                                        <li class="text-muted">{{ __('No views of this page in the period.') }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-signal.ui.card>
        @elseif ($tab === 'properties')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="properties-heading">
                <h2 id="properties-heading" class="text-lg font-extrabold text-ink">{{ __('Custom property breakdown') }}</h2>
                @if (($site->custom_properties ?? []) === [])
                    <p class="text-sm text-muted">{{ __('List the property names to keep in the site’s settings, then send them with custom events, such as buildpusher.track(\'signup\', { plan: \'pro\' }).') }} <a class="ui-link" href="{{ route('analytics.sites.show', [$project, $site->id]) }}">{{ __('Site settings') }}</a></p>
                @elseif ($result['values'] === [])
                    <p class="text-sm text-muted">{{ __('Choose an event and a property to see its values.') }}</p>
                @else
                    <x-signal.ui.table :caption="__('Values of :property on :event', ['property' => $property, 'event' => $event])" :framed="false">
                        <x-slot:head><tr><th scope="col">{{ $property }}</th><th scope="col" class="text-right">{{ __('Events') }}</th><th scope="col" class="text-right">{{ __('Visitors') }}</th><th scope="col" class="text-right">{{ __('Revenue') }}</th></tr></x-slot:head>
                        @foreach ($result['values'] as $row)
                            <tr><td class="font-bold text-ink">{{ $row['label'] }}</td><td class="text-right tabular-nums">{{ number_format($row['events']) }}</td><td class="text-right tabular-nums">{{ number_format($row['visitors']) }}</td><td class="text-right tabular-nums">{{ $row['revenue'] > 0 ? number_format($row['revenue'], 2) : '—' }}</td></tr>
                        @endforeach
                    </x-signal.ui.table>
                @endif
            </x-signal.ui.card>
        @elseif ($tab === 'items')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="items-heading">
                <div>
                    <h2 id="items-heading" class="text-lg font-extrabold text-ink">{{ __('Items sold') }}</h2>
                    <p class="text-sm text-muted">{{ __('From events that list their items, such as buildpusher.track(\'purchase\', { revenue: 30, currency: \'USD\', items: [{ id: \'sku-1\', name: \'Pro plan\', price: 30, quantity: 1 }] }).') }}</p>
                </div>
                @if ($result === [])
                    <p class="text-sm text-muted">{{ __('No items in this period.') }}</p>
                @else
                    <x-signal.ui.table :caption="__('Items sold')" :framed="false">
                        <x-slot:head><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Category') }}</th><th scope="col" class="text-right">{{ __('Quantity') }}</th><th scope="col" class="text-right">{{ __('Orders') }}</th><th scope="col" class="text-right">{{ __('Revenue') }}</th></tr></x-slot:head>
                        @foreach ($result as $row)
                            <tr><td class="font-bold text-ink">{{ $row['item'] }}</td><td class="text-muted">{{ $row['category'] ?? '—' }}</td><td class="text-right tabular-nums">{{ number_format($row['quantity']) }}</td><td class="text-right tabular-nums">{{ number_format($row['orders']) }}</td><td class="text-right tabular-nums">{{ $money($row['revenue'], $row['currency']) }}</td></tr>
                        @endforeach
                    </x-signal.ui.table>
                @endif
            </x-signal.ui.card>
        @elseif ($tab === 'attribution')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="attribution-heading">
                <div>
                    <h2 id="attribution-heading" class="text-lg font-extrabold text-ink">{{ __('Who gets the credit') }}</h2>
                    <p class="text-sm text-muted">{{ __('Last touch credits where the converting visit came from; first touch credits where the visitor first came from. First touch needs data-retention on the snippet to look past a single visit.') }}</p>
                </div>
                @if ($result === [])
                    <p class="text-sm text-muted">{{ __('No goal completions in this period.') }}</p>
                @else
                    <x-signal.ui.table :caption="__('Attribution')" :framed="false">
                        <x-slot:head><tr><th scope="col">{{ $by === 'campaign' ? __('Campaign') : __('Channel') }}</th><th scope="col" class="text-right">{{ __('First touch') }}</th><th scope="col" class="text-right">{{ __('Last touch') }}</th><th scope="col" class="text-right">{{ __('Revenue (first)') }}</th><th scope="col" class="text-right">{{ __('Revenue (last)') }}</th></tr></x-slot:head>
                        @foreach ($result as $row)
                            <tr><td class="font-bold text-ink">{{ $row['label'] }}</td><td class="text-right tabular-nums">{{ number_format($row['first']) }}</td><td class="text-right tabular-nums">{{ number_format($row['last']) }}</td><td class="text-right tabular-nums">{{ $row['first_revenue'] ?? '—' }}</td><td class="text-right tabular-nums">{{ $row['last_revenue'] ?? '—' }}</td></tr>
                        @endforeach
                    </x-signal.ui.table>
                @endif
            </x-signal.ui.card>
        @elseif ($tab === 'clicks')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="clicks-heading">
                <div>
                    <h2 id="clicks-heading" class="text-lg font-extrabold text-ink">{{ $path ? __('Clicks on :path', ['path' => $path]) : __('Pages with the most clicks') }}</h2>
                    <p class="text-sm text-muted">{{ __('Add data-clicks to the snippet to record where people click. Only a short description of the element and its position are kept.') }}</p>
                </div>
                @if ($path === null)
                    <ul class="grid gap-2 text-sm">
                        @forelse ($result['pages'] as $row)
                            <li class="flex justify-between gap-4"><a class="truncate text-primary hover:underline" href="{{ $here(['path' => $row['label']]) }}">{{ $row['label'] }}</a><strong class="tabular-nums text-ink">{{ number_format($row['value']) }}</strong></li>
                        @empty
                            <li class="text-muted">{{ __('No clicks recorded in this period.') }}</li>
                        @endforelse
                    </ul>
                @else
                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <figure class="grid gap-2">
                            <svg viewBox="0 0 100 160" class="w-full rounded-control border border-line bg-surface-muted" role="img" aria-label="{{ __('Where people clicked on the page, top to bottom') }}">
                                @foreach ($result['points'] as $point)
                                    <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] * 1.6 }}" r="1.2" fill="var(--product-analytics)" fill-opacity="0.35" />
                                @endforeach
                            </svg>
                            <figcaption class="text-xs text-muted">{{ trans_choice(':count click, placed by its position on the page (top to bottom).|:count clicks, placed by their position on the page (top to bottom).', count($result['points']), ['count' => number_format(count($result['points']))]) }}</figcaption>
                        </figure>
                        <div>
                            <h3 class="text-sm font-extrabold text-ink">{{ __('Most clicked') }}</h3>
                            <ul class="mt-2 grid gap-2 text-sm">
                                @forelse ($result['targets'] as $row)
                                    <li class="flex justify-between gap-4"><span class="truncate font-mono text-xs text-muted">{{ $row['label'] }}</span><strong class="tabular-nums text-ink">{{ number_format($row['value']) }}</strong></li>
                                @empty
                                    <li class="text-muted">{{ __('No clicks on this page in the period.') }}</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                @endif
            </x-signal.ui.card>
        @elseif ($tab === 'forms')
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="forms-heading">
                <div>
                    <h2 id="forms-heading" class="text-lg font-extrabold text-ink">{{ __('Forms') }}</h2>
                    <p class="text-sm text-muted">{{ __('Add data-forms to the snippet to see who starts each form, who sends it, and which field people leave on. What people type is never recorded.') }}</p>
                </div>
                @forelse ($result as $form)
                    <div class="grid gap-2 border-t border-line pt-4">
                        <p class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-mono font-bold text-ink">{{ $form['form'] }}</span>
                            <span class="text-sm text-muted">{{ __(':started started · :submitted sent · :rate% completed', ['started' => number_format($form['started']), 'submitted' => number_format($form['submitted']), 'rate' => $form['started'] > 0 ? round($form['submitted'] / $form['started'] * 100) : 0]) }}</span>
                        </p>
                        <x-signal.ui.table :caption="__('Fields of :form', ['form' => $form['form']])" :framed="false">
                            <x-slot:head><tr><th scope="col">{{ __('Field') }}</th><th scope="col" class="text-right">{{ __('Reached') }}</th><th scope="col" class="text-right">{{ __('Left here') }}</th></tr></x-slot:head>
                            @foreach ($form['fields'] as $field)
                                <tr><td class="font-mono text-xs">{{ $field['field'] }}</td><td class="text-right tabular-nums">{{ number_format($field['reached']) }}</td><td @class(['text-right tabular-nums', 'font-bold text-danger' => $field['left'] > 0])>{{ number_format($field['left']) }}</td></tr>
                            @endforeach
                        </x-signal.ui.table>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No form activity in this period.') }}</p>
                @endforelse
            </x-signal.ui.card>
        @else
            <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="retention-heading">
                <div>
                    <h2 id="retention-heading" class="text-lg font-extrabold text-ink">{{ __('Weekly retention') }}</h2>
                    <p class="text-sm text-muted">{{ __('Visitors grouped by the week they first came, and the share who came back each week after.') }}</p>
                </div>
                @if (! $result['tracked'])
                    <x-signal.ui.alert tone="info">{{ __('Add data-retention to the snippet to recognise returning browsers. It keeps a random ID in the visitor’s browser, so ask for consent where the law requires it.') }}</x-signal.ui.alert>
                @else
                    <x-signal.ui.table :caption="__('Weekly retention')" :framed="false">
                        <x-slot:head><tr><th scope="col">{{ __('First week') }}</th><th scope="col" class="text-right">{{ __('Visitors') }}</th>@for ($after = 1; $after <= 7; $after++)<th scope="col" class="text-right">{{ __('Week :n', ['n' => $after]) }}</th>@endfor</tr></x-slot:head>
                        @foreach ($result['cohorts'] as $cohort)
                            <tr>
                                <td class="whitespace-nowrap">{{ $cohort['week']->isoFormat('D MMM') }}</td>
                                <td class="text-right tabular-nums">{{ number_format($cohort['size']) }}</td>
                                @foreach ($cohort['returned'] as $share)
                                    <td class="text-right tabular-nums">{{ $share === null ? '' : $share.'%' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </x-signal.ui.table>
                @endif
            </x-signal.ui.card>
        @endif
    @endif
</x-signal.layouts.project>
