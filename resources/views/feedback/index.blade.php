<x-layouts.app>
    <x-layouts.partials.heading
        icon="information-circle"
        :title="__('Product feedback')"
        :description="__('Report a bug, share an idea, or tell us where the product became confusing.')"
    />

    <div class="mt-8 grid gap-6 xl:grid-cols-[22rem_1fr]">
        <x-ui.card class="h-fit p-5 sm:p-6">
            <h2 class="text-lg font-black text-primary">{{ __('Send private feedback') }}</h2>
            <p class="mt-1 text-sm leading-6 text-secondary">{{ __('Visible only to you and workspace administrators. Never include passwords, tokens, private keys, or environment values.') }}</p>
            <form method="POST" action="{{ route('feedback.store') }}" class="mt-5 space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="feedback-category" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Type') }}</label>
                        <select id="feedback-category" name="category" class="input secondary mt-2 w-full rounded-lg">
                            @foreach (\App\Models\ProductFeedback::CATEGORIES as $value)
                                <option value="{{ $value }}">{{ str($value)->headline() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="feedback-severity" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Impact') }}</label>
                        <select id="feedback-severity" name="severity" class="input secondary mt-2 w-full rounded-lg">
                            @foreach (\App\Models\ProductFeedback::SEVERITIES as $value)
                                <option value="{{ $value }}" @selected($value === 'normal')>{{ str($value)->headline() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="feedback-title" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Summary') }}</label>
                    <input id="feedback-title" name="title" maxlength="160" required value="{{ old('title') }}" class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('What happened or should change?') }}">
                </div>
                <div>
                    <label for="feedback-description" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Details') }}</label>
                    <textarea id="feedback-description" name="description" rows="5" maxlength="10000" required class="input secondary mt-2 w-full rounded-lg" placeholder="{{ __('Describe the outcome you expected and what you saw.') }}">{{ old('description') }}</textarea>
                </div>
                <div>
                    <label for="feedback-reproduction" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Steps to reproduce (optional)') }}</label>
                    <textarea id="feedback-reproduction" name="reproduction_steps" rows="4" maxlength="10000" class="input secondary mt-2 w-full rounded-lg" placeholder="1. Open…">{{ old('reproduction_steps') }}</textarea>
                </div>
                <div>
                    <label for="feedback-page" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Related page (optional)') }}</label>
                    <input id="feedback-page" name="page" maxlength="500" value="{{ old('page', request()->query('from')) }}" class="input secondary mt-2 w-full rounded-lg font-mono" placeholder="/projects/12">
                </div>
                <x-forms.errors name="category" />
                <x-forms.errors name="severity" />
                <x-forms.errors name="title" />
                <x-forms.errors name="description" />
                <x-forms.errors name="reproduction_steps" />
                <x-forms.errors name="page" />
                <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Submit feedback') }}</x-ui.button>
            </form>
        </x-ui.card>

        <section aria-labelledby="feedback-list-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="feedback-list-heading" class="text-lg font-black text-primary">{{ __('Workspace feedback') }}</h2>
                <form method="GET" class="flex flex-wrap gap-2">
                    <label class="sr-only" for="feedback-status">{{ __('Status') }}</label>
                    <select id="feedback-status" name="status" class="input secondary rounded-lg"><option value="">{{ __('Every status') }}</option>@foreach (\App\Models\ProductFeedback::STATUSES as $value)<option value="{{ $value }}" @selected($status === $value)>{{ str($value)->headline() }}</option>@endforeach</select>
                    <label class="sr-only" for="feedback-filter-category">{{ __('Type') }}</label>
                    <select id="feedback-filter-category" name="category" class="input secondary rounded-lg"><option value="">{{ __('Every type') }}</option>@foreach (\App\Models\ProductFeedback::CATEGORIES as $value)<option value="{{ $value }}" @selected($category === $value)>{{ str($value)->headline() }}</option>@endforeach</select>
                    <x-ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-ui.button>
                    @if ($status || $category)
                        <x-ui.button href="{{ route('feedback.index') }}" variant="ghost">{{ __('Clear') }}</x-ui.button>
                    @endif
                </form>
            </div>

            <div class="space-y-4">
                @forelse ($feedback as $item)
                    <x-ui.card class="p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.badge tone="neutral">{{ str($item->category)->headline() }}</x-ui.badge>
                                    <x-ui.badge :tone="$item->severity === 'critical' ? 'danger' : ($item->severity === 'high' ? 'warning' : 'neutral')">{{ str($item->severity)->headline() }}</x-ui.badge>
                                    <x-ui.badge :tone="$item->status === 'resolved' ? 'success' : ($item->status === 'in_progress' ? 'accent' : 'neutral')">{{ str($item->status)->headline() }}</x-ui.badge>
                                </div>
                                <h3 class="mt-3 text-lg font-black text-primary">{{ $item->title }}</h3>
                                <p class="mt-1 text-xs text-secondary">{{ __('Submitted by :name :time', ['name' => $item->submitter->name, 'time' => $item->created_at->diffForHumans()]) }}@if ($item->page) <span aria-hidden="true">·</span> <code>{{ $item->page }}</code>@endif</p>
                            </div>
                            <form method="POST" action="{{ route('feedback.destroy', $item) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Remove this feedback permanently?')) }})">{{ __('Delete') }}</x-ui.button>
                            </form>
                        </div>

                        <p class="mt-4 whitespace-pre-wrap text-sm leading-6 text-secondary">{{ $item->description }}</p>
                        @if ($item->reproduction_steps)
                            <details class="mt-3">
                                <summary class="cursor-pointer text-sm font-bold text-ternary">{{ __('Reproduction steps') }}</summary>
                                <p class="mt-2 whitespace-pre-wrap rounded-lg bg-secondary p-3 text-sm text-primary">{{ $item->reproduction_steps }}</p>
                            </details>
                        @endif
                        @if ($item->review_response)
                            <div class="ui-card mt-4 bg-secondary p-4">
                                <p class="text-xs font-bold uppercase text-secondary">{{ __('Workspace response') }}</p>
                                <p class="mt-2 whitespace-pre-wrap text-sm text-primary">{{ $item->review_response }}</p>
                            </div>
                        @endif
                        @if ($canReview)
                            <details class="mt-4">
                                <summary class="cursor-pointer text-sm font-bold text-ternary">{{ __('Review feedback') }}</summary>
                                <form method="POST" action="{{ route('feedback.update', $item) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block"><span class="sr-only">{{ __('Status') }}</span><select name="status" class="input secondary w-full rounded-lg">@foreach (\App\Models\ProductFeedback::STATUSES as $value)<option value="{{ $value }}" @selected($item->status === $value)>{{ str($value)->headline() }}</option>@endforeach</select></label>
                                    <label class="block"><span class="sr-only">{{ __('Workspace response') }}</span><textarea name="review_response" rows="3" maxlength="10000" class="input secondary w-full rounded-lg" placeholder="{{ __('Decision, workaround, or planned resolution') }}">{{ $item->review_response }}</textarea></label>
                                    <x-ui.button type="submit" variant="primary">{{ __('Save review') }}</x-ui.button>
                                </form>
                            </details>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.empty-state
                        :title="__('No matching feedback')"
                        :description="__('Submitted feedback will appear here without exposing its content in notifications or exports.')"
                    />
                @endforelse
            </div>
            <div class="mt-5">{{ $feedback->links() }}</div>
        </section>
    </div>
</x-layouts.app>
