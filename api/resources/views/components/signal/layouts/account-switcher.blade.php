@props(['shell', 'variant' => 'topbar'])

@if ($shell->account !== null)
    <x-signal.layouts.switcher
        {{ $attributes }}
        :variant="$variant"
        :label="__('Account')"
        :current="$shell->account->name"
        icon="user-circle"
        :items="array_map(fn (array $account): array => ['name' => $account['name'], 'url' => route('accounts.switch', $account['id']), 'current' => $account['id'] === $shell->account->id, 'method' => 'post'], $shell->accounts)"
    />
@endif
