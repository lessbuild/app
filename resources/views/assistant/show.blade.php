{{-- The assistant: ask about deploys, errors, checks, incidents, Analytics and servers; or connect your own AI tools. --}}
@if ($conversation?->status === 'thinking')
    @push('head')<meta http-equiv="refresh" content="3">@endpush
@endif
<x-signal.layouts.app :title="__('Ask')">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="__('Ask')" :description="__('Ask about your deploys, errors, uptime checks, incidents, Analytics and servers in plain language, like “why did errors go up after the last deploy?”. Answers only use what you can see.')" />

    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="grid content-start gap-4">
            @if (! $configured)
                <x-signal.ui.alert tone="info">{{ __('The assistant isn’t set up on this installation yet. You can still ask from your own AI tools; see Use it from your AI tools.') }}</x-signal.ui.alert>
            @endif
            @if ($conversation)
                <x-signal.ui.panel as="section" class="grid gap-4 p-4 sm:p-6" aria-live="polite">
                    @foreach ($conversation->transcript() as $line)
                        <div @class(['rounded-card p-3', 'bg-primary-soft' => $line['role'] === 'user'])>
                            <p class="ui-eyebrow">{{ $line['role'] === 'user' ? __('You') : __('Assistant') }}</p>
                            @if ($line['role'] === 'user')
                                <p class="mt-1 whitespace-pre-wrap text-sm text-ink">{{ $line['text'] }}</p>
                            @else
                                <div class="ui-prose mt-1 text-sm text-ink">{!! Str::markdown($line['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                            @endif
                        </div>
                    @endforeach
                    @if ($conversation->status === 'thinking')
                        <p class="text-sm text-muted" role="status">{{ __('Looking into it…') }}</p>
                    @elseif ($conversation->status === 'failed')
                        <x-signal.ui.alert tone="danger">{{ $conversation->error }}</x-signal.ui.alert>
                    @endif
                </x-signal.ui.panel>
            @endif
            @if ($configured)
                <form method="POST" action="{{ route('assistant.ask') }}" class="grid gap-3">
                    @csrf
                    @if ($conversation)<input type="hidden" name="conversation" value="{{ $conversation->id }}">@endif
                    <x-signal.ui.textarea-field name="question" :label="$conversation ? __('Ask a follow-up') : __('Your question')" rows="3" required :restore="false" />
                    <div class="flex flex-wrap gap-3">
                        <x-signal.ui.button type="submit" variant="primary" :disabled="$conversation?->status === 'thinking'">{{ __('Ask') }}</x-signal.ui.button>
                        @if ($conversation)<x-signal.ui.button :href="route('assistant')" variant="quiet">{{ __('New conversation') }}</x-signal.ui.button>@endif
                    </div>
                </form>
            @endif
        </div>

        <div class="grid content-start gap-4">
            @if ($conversations->isNotEmpty())
                <x-signal.ui.panel as="nav" class="grid gap-1 p-4" :aria-label="__('Recent conversations')">
                    <p class="ui-eyebrow">{{ __('Recent conversations') }}</p>
                    @foreach ($conversations as $recent)
                        <a href="{{ route('assistant', ['conversation' => $recent->id]) }}" class="truncate text-sm text-ink hover:underline" @if ($conversation?->id === $recent->id) aria-current="page" @endif>{{ $recent->title }}</a>
                    @endforeach
                    <p class="mt-2 text-xs text-muted">{{ __('Kept for 30 days.') }}</p>
                </x-signal.ui.panel>
            @endif
            <x-signal.ui.panel as="section" class="grid gap-2 p-4" aria-labelledby="mcp-heading">
                <h2 id="mcp-heading" class="text-sm font-bold text-ink">{{ __('Use it from your AI tools') }}</h2>
                <p class="text-xs text-muted">{{ __('Add this MCP server to Claude, Cursor or another AI tool, with an API token from Account → API tokens as the bearer token. The tools see what the token’s scopes allow.') }}</p>
                <x-signal.ui.code-block :code="$mcpUrl" class="break-all whitespace-pre-wrap" />
            </x-signal.ui.panel>
        </div>
    </div>
</x-signal.layouts.app>
