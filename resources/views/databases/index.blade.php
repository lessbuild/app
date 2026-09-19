<x-layouts.app title="{{ __('Databases') }}">
    <x-layouts.partials.heading
        eyebrow="{{ __('Data operations') }}"
        icon="database"
        :title="__('Database operations')"
        :description="__('Inspect managed databases, issue expiring credentials, and safely clone data into non-production environments.')"
    />

    @unless ($featureAvailable)
        <x-ui.alert tone="warning" class="mt-6">
            <p class="font-semibold">{{ __('Pro feature') }}</p>
            <p class="mt-1">{{ __('Upgrade to inspect databases, issue credentials, and run safe clones.') }}</p>
            <x-ui.button :href="route('pricing')" variant="secondary" class="mt-3">{{ __('Compare plans') }}</x-ui.button>
        </x-ui.alert>
    @endunless

    @if (session('databasePassword'))
        <div class="ui-alert ui-alert--warning mt-6" role="status">
            <p class="font-bold text-primary">{{ __('Copy this password now') }}</p>
            <code class="mt-2 block break-all rounded-lg bg-primary p-3 text-sm text-primary">{{ session('databasePassword') }}</code>
        </div>
    @endif

    @php
        $readyResourceCount = $resources->where('status', \App\Models\EnvironmentResource::STATUS_READY)->count();
        $credentialCount = $resources->sum(fn ($resource) => $resource->databaseUsers->count());
    @endphp

    <x-ui.insights
        id="database-insights"
        class="mt-6"
        :summary="trans_choice(':count managed database resource|:count managed database resources', $resources->count(), ['count' => $resources->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Managed resources')"
                :value="$resources->count()"
                :description="__('MySQL and PostgreSQL resources in this workspace.')"
            />
            <x-ui.stat
                :label="__('Ready')"
                :value="$readyResourceCount"
                :description="__('Resources with a usable inspection state.')"
            />
            <x-ui.stat
                :label="__('Active credentials')"
                :value="$credentialCount"
                :description="__('Issued database credentials across resources.')"
            />
            <x-ui.stat
                :label="__('Recent clones')"
                :value="$clones->count()"
                :description="__('Retained clone operations shown below.')"
            />
        </dl>
    </x-ui.insights>

    <div class="mt-8 grid gap-5 xl:grid-cols-2">
        @forelse ($resources as $resource)
            @php
                $latest = $resource->snapshots->first();
                $databaseCredentialDialogId = 'database-credential-'.$resource->id;
                $databaseCredentialHasErrors = old('_database_credential_resource') == $resource->id
                    && $errors->hasAny(['username', 'privilege', 'expires_in_days']);
                $databaseCredentialDialogOpen = (request()->query('dialog') === 'issue-credential'
                    && (string) request()->query('resource_id') === (string) $resource->id
                    && ! session()->has('success')) || $databaseCredentialHasErrors;
                $databaseCredentialDialogUrl = route('databases.index', [
                    'dialog' => 'issue-credential',
                    'resource_id' => $resource->id,
                ]);
            @endphp

            <section class="ui-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <x-ui.badge tone="accent">{{ strtoupper($resource->type) }}</x-ui.badge>
                        <h2 class="mt-2 break-words text-lg font-black text-primary">{{ $resource->name }}</h2>
                        <p class="text-sm text-secondary">{{ $resource->environment->project->name }} · {{ $resource->environment->name }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-2">
                        <form method="POST" action="{{ route('databases.inspect', $resource) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary">{{ __('Inspect') }}</x-ui.button>
                        </form>
                        @if ($canManage)
                            <x-ui.button
                                href="{{ $databaseCredentialDialogUrl }}"
                                data-modal-trigger="{{ $databaseCredentialDialogId }}"
                                aria-controls="{{ $databaseCredentialDialogId }}"
                                aria-expanded="{{ $databaseCredentialDialogOpen ? 'true' : 'false' }}"
                                variant="primary"
                            >
                                {{ __('Issue credential') }}
                            </x-ui.button>
                        @endif
                    </div>
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
                    @php
                        $databaseManagementOpen = $resource->databaseUsers->isEmpty()
                            || session()->has('databasePassword')
                            || $errors->has('username')
                            || $errors->has('privilege')
                            || $errors->has('expires_in_days')
                            || $errors->has('target_resource_id')
                            || $errors->has('confirmation');
                    @endphp
                    <details id="database-management-{{ $resource->id }}" class="group mt-5 overflow-hidden rounded-xl border border-primary" @if ($databaseManagementOpen) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 font-bold text-primary [&::-webkit-details-marker]:hidden">
                            <span>
                                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Operations') }}</span>
                                <span class="mt-1 block">{{ __('Credentials and cloning') }}</span>
                                <span class="mt-1 block text-sm font-normal text-secondary">
                                    {{ trans_choice(':count active credential|:count active credentials', $resource->databaseUsers->count(), ['count' => $resource->databaseUsers->count()]) }}
                                    · {{ __('Non-production replacement only') }}
                                </span>
                            </span>
                            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
                        </summary>
                        <div class="space-y-5 border-t border-primary p-4">
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

                    <form method="POST" action="{{ route('databases.clone', $resource) }}" class="space-y-3 border-t border-primary pt-5">
                        @csrf
                        <div>
                            <h3 class="font-bold text-primary">{{ __('Clone this database') }}</h3>
                            <p class="mt-1 text-xs text-secondary">{{ __('Only non-production targets are offered. Type the target name to confirm.') }}</p>
                        </div>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Target environment') }}</span>
                            <select name="target_resource_id" class="input secondary w-full rounded-lg" required>
                                <option value="">{{ __('Clone into…') }}</option>
                                @foreach ($resources->where('type', $resource->type)->where('id', '!=', $resource->id)->filter(fn ($target) => $target->environment->type !== 'production') as $target)
                                    <option value="{{ $target->id }}">{{ $target->environment->project->name }} / {{ $target->environment->name }} / {{ $target->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Confirmation') }}</span>
                            <input name="confirmation" class="input secondary w-full rounded-lg" placeholder="{{ __('Type the target resource name to confirm') }}" required>
                        </label>
                        <x-ui.button type="submit" variant="danger">{{ __('Queue destructive clone') }}</x-ui.button>
                    </form>
                        </div>
                    </details>

                    <x-scenes.databases.credential-dialog
                        :resource="$resource"
                        :open="$databaseCredentialDialogOpen"
                    />
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
