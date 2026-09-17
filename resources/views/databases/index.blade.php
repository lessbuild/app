<x-layouts.app title="{{ __('Databases') }}">
    <x-layouts.partials.heading
        icon="database"
        :title="__('Database operations')"
        :description="__('Inspect managed databases, issue expiring credentials, and safely clone data into non-production environments.')"
    />

    @unless ($featureAvailable)
        <x-alerts.info
            class="mt-6"
            :title="__('Pro feature')"
            :link="route('pricing')"
            :anchor="__('Compare plans')"
        >
            {{ __('Upgrade to inspect databases, issue credentials, and run safe clones.') }}
        </x-alerts.info>
    @endunless

    @if (session('databasePassword'))
        <div class="ui-alert ui-alert--warning mt-6" role="status">
            <p class="font-bold text-primary">{{ __('Copy this password now') }}</p>
            <code class="mt-2 block break-all rounded-md bg-primary p-3 text-sm text-primary">{{ session('databasePassword') }}</code>
        </div>
    @endif

    <div class="mt-8 grid gap-5 xl:grid-cols-2">
        @forelse ($resources as $resource)
            @php($latest = $resource->snapshots->first())

            <section class="ui-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <x-ui.badge tone="accent">{{ strtoupper($resource->type) }}</x-ui.badge>
                        <h2 class="mt-2 break-words text-lg font-black text-primary">{{ $resource->name }}</h2>
                        <p class="text-sm text-secondary">{{ $resource->environment->project->name }} · {{ $resource->environment->name }}</p>
                    </div>
                    <form method="POST" action="{{ route('databases.inspect', $resource) }}" class="shrink-0">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('Inspect') }}</x-ui.button>
                    </form>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3">
                    <div class="ui-card ui-card--muted p-3">
                        <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Size') }}</dt>
                        <dd class="mt-1 font-bold text-primary">{{ $latest?->size_bytes ? number_format($latest->size_bytes / 1048576, 1).' MB' : '—' }}</dd>
                    </div>
                    <div class="ui-card ui-card--muted p-3">
                        <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Connections') }}</dt>
                        <dd class="mt-1 font-bold text-primary">{{ $latest?->active_connections ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($latest?->schema_tables)
                    <details class="mt-4 rounded-lg border border-primary px-3 py-2">
                        <summary class="cursor-pointer text-sm font-bold text-secondary">
                            {{ count($latest->schema_tables) }} {{ __('tables') }}
                        </summary>
                        <p class="mt-2 break-words font-mono text-xs text-secondary">{{ implode(' · ', $latest->schema_tables) }}</p>
                    </details>
                @endif

                @if ($canManage)
                    <form method="POST" action="{{ route('databases.users.store', $resource) }}" class="mt-5 space-y-3 border-t border-primary pt-5">
                        @csrf
                        <div>
                            <h3 class="font-bold text-primary">{{ __('Issue a database credential') }}</h3>
                            <p class="mt-1 text-xs text-secondary">{{ __('Credentials can be limited by privilege and expiration.') }}</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Username') }}</span>
                                <input name="username" class="input secondary w-full rounded-md" placeholder="report_reader" required>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Privilege') }}</span>
                                <select name="privilege" class="input secondary w-full rounded-md">
                                    <option value="read">{{ __('Read only') }}</option>
                                    <option value="write">{{ __('Read/write') }}</option>
                                    <option value="admin">{{ __('Admin') }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Expires') }}</span>
                                <select name="expires_in_days" class="input secondary w-full rounded-md">
                                    <option value="">{{ __('Never expires') }}</option>
                                    <option value="1">1 day</option>
                                    <option value="7">7 days</option>
                                    <option value="30">30 days</option>
                                    <option value="90">90 days</option>
                                </select>
                            </label>
                            <div class="flex items-end">
                                <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Create credential') }}</x-ui.button>
                            </div>
                        </div>
                    </form>

                    <div class="mt-4 space-y-2" aria-label="{{ __('Database credentials') }}">
                        @foreach ($resource->databaseUsers as $databaseUser)
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-secondary p-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-primary">{{ $databaseUser->username }}</p>
                                    <p class="text-xs text-secondary">
                                        {{ ucfirst($databaseUser->privilege) }} · {{ $databaseUser->expires_at ? __('expires').' '.$databaseUser->expires_at->diffForHumans() : __('permanent') }}
                                    </p>
                                </div>
                                <form method="POST" action="{{ route('databases.users.destroy', $databaseUser) }}" class="shrink-0">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger">{{ __('Revoke') }}</x-ui.button>
                                </form>
                            </div>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('databases.clone', $resource) }}" class="mt-5 space-y-3 border-t border-primary pt-5">
                        @csrf
                        <div>
                            <h3 class="font-bold text-primary">{{ __('Clone this database') }}</h3>
                            <p class="mt-1 text-xs text-secondary">{{ __('Only non-production targets are offered. Type the target name to confirm.') }}</p>
                        </div>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Target environment') }}</span>
                            <select name="target_resource_id" class="input secondary w-full rounded-md" required>
                                <option value="">{{ __('Clone into…') }}</option>
                                @foreach ($resources->where('type', $resource->type)->where('id', '!=', $resource->id)->filter(fn ($target) => $target->environment->type !== 'production') as $target)
                                    <option value="{{ $target->id }}">{{ $target->environment->project->name }} / {{ $target->environment->name }} / {{ $target->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Confirmation') }}</span>
                            <input name="confirmation" class="input secondary w-full rounded-md" placeholder="{{ __('Type the target resource name to confirm') }}" required>
                        </label>
                        <x-ui.button type="submit" variant="danger">{{ __('Queue destructive clone') }}</x-ui.button>
                    </form>
                @endif
            </section>
        @empty
            <x-ui.empty-state
                class="xl:col-span-2"
                :title="__('No managed databases')"
                :description="__('Attach a MySQL or PostgreSQL resource to use database operations.')"
                icon="database"
            />
        @endforelse
    </div>

    @if ($clones->isNotEmpty())
        <section class="ui-card mt-6 p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-black text-primary">{{ __('Clone history') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Review recent database copy operations and their outcomes.') }}</p>
                </div>
                <x-ui.badge>{{ $clones->count() }}</x-ui.badge>
            </div>
            <div class="mt-4 space-y-2">
                @foreach ($clones as $clone)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-secondary p-3 text-sm">
                        <span class="text-primary">{{ $clone->source->name }} → {{ $clone->target->name }}</span>
                        <x-ui.badge tone="{{ in_array($clone->status, ['completed', 'succeeded'], true) ? 'success' : 'neutral' }}">{{ ucfirst($clone->status) }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
