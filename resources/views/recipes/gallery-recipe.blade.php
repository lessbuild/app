<x-signal.layouts.account :account="$account" :title="$recipe->name" :description="$recipe->description ?? __('A gallery recipe.')">
    @include('recipes._nav')
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @foreach (['rating', 'reason', 'details'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach

    <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-4 p-5">
        <div class="text-sm">
            <p>{{ $recipe->category->label() }} · {{ __('by :account', ['account' => $recipe->account->name]) }} · {{ trans_choice(':count install|:count installs', $recipe->install_count) }}</p>
            <p class="text-muted">@if ($recipe->ratings_count > 0){{ __(':average★ from :count ratings', ['average' => number_format((float) $recipe->ratings_avg_rating, 1), 'count' => $recipe->ratings_count]) }}@else{{ __('Not rated yet') }}@endif · {{ __('updated :when', ['when' => $recipe->gallery_revision_at?->diffForHumans()]) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('recipes.gallery.favorite', $recipe->id) }}">@csrf @method($favorited ? 'DELETE' : 'PUT')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ $favorited ? __('Remove from favourites') : __('Add to favourites') }}</x-signal.ui.button></form>
            @if ($copy)
                <x-signal.ui.button :href="route('account.recipes.show', $copy->id)" variant="secondary" size="sm">{{ $copy->hasGalleryUpdate() ? __('Update your copy') : __('Open your copy') }}</x-signal.ui.button>
            @elseif ($canInstall)
                <form method="POST" action="{{ route('recipes.gallery.install', $recipe->id) }}">@csrf<x-signal.ui.button type="submit" variant="primary" size="sm">{{ __('Install into :account', ['account' => $account->name]) }}</x-signal.ui.button></form>
            @endif
        </div>
    </x-signal.ui.card>

    <x-signal.ui.settings-section :title="__('Script')" :description="__('Read it first: it runs as root at the end of a new server’s provisioning.')">
        <div class="p-4 sm:p-6"><x-signal.ui.code-block :code="$recipe->script" class="max-h-[32rem] overflow-auto text-xs" /></div>
    </x-signal.ui.settings-section>

    @if ($canRate)
        <x-signal.ui.settings-section :title="__('Your rating')" :description="__('Your account installed it, so your rating helps others choose.')">
            <div class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                <form method="POST" action="{{ route('recipes.gallery.rating', $recipe->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <x-signal.ui.select-field name="rating" :label="__('Stars')">
                        @foreach ([5, 4, 3, 2, 1] as $stars)
                            <option value="{{ $stars }}" @selected($rating?->rating === $stars)>{{ str_repeat('★', $stars) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.button type="submit" variant="secondary">{{ $rating ? __('Change rating') : __('Rate') }}</x-signal.ui.button>
                </form>
                @if ($rating)
                    <form method="POST" action="{{ route('recipes.gallery.rating', $recipe->id) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Withdraw') }}</x-signal.ui.button></form>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @endif

    @if ($canReport)
        <x-signal.ui.settings-section :title="__('Report a problem')" :description="$report ? __('You reported it (:status). Changing the report opens it again.', ['status' => $report->status === 'open' ? __('open') : __('resolved')]) : __('Tell the publisher about a harmful, broken or spam recipe.')">
            <form method="POST" action="{{ route('recipes.gallery.report', $recipe->id) }}" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.select-field name="reason" :label="__('Reason')">
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason->value }}" @selected($report?->reason === $reason)>{{ $reason->label() }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <div class="sm:col-span-2"><x-signal.ui.textarea-field name="details" :label="__('Details (optional)')" rows="3" :value="old('details', $report?->details)" maxlength="2000" /></div>
                @if ($report?->resolution_note)<p class="text-sm text-muted sm:col-span-2">{{ __('Publisher: :note', ['note' => $report->resolution_note]) }}</p>@endif
                <div class="flex gap-2 sm:col-span-2">
                    <x-signal.ui.button type="submit" variant="secondary">{{ $report ? __('Update report') : __('Send report') }}</x-signal.ui.button>
                </div>
            </form>
            @if ($report)
                <form method="POST" action="{{ route('recipes.gallery.report', $recipe->id) }}" class="px-4 pb-4 sm:px-6">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Withdraw report') }}</x-signal.ui.button></form>
            @endif
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.account>
