@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Move from Forge or Ploi')" :description="__('Read your servers and sites from Laravel Forge or Ploi, then recreate each site on a server here with its environment file, cron jobs and daemons. Nothing changes in the other tool.')">
    @error('site')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @error('plan')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    <x-signal.ui.settings-section :title="__('Connect')" :description="__('Create an API token in the other tool (Forge: Account → API tokens; Ploi: Profile → API keys) and paste it here. It’s stored encrypted and only read from.')">
        <form method="POST" action="{{ route('infrastructure.moves.store', $project) }}" class="grid items-end gap-4 p-4 sm:grid-cols-[12rem_1fr_auto] sm:p-6">
            @csrf
            <x-signal.ui.select-field id="move-source" name="source" :label="__('From')">
                @foreach (\App\Models\ToolMove::SOURCES as $value => $label)
                    <option value="{{ $value }}" @selected(old('source') === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.input-field id="move-token" name="token" type="password" :label="__('API token')" autocomplete="off" :restore="false" required />
            <x-signal.ui.button type="submit" variant="primary">{{ __('Read servers and sites') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.settings-section>

    @foreach ($moves as $move)
        <x-signal.ui.settings-section :title="__('From :tool', ['tool' => $move->sourceName()])" :description="__('Read :time. Choose a server here for each site and move it; then follow the steps below.', ['time' => $move->fetched_at?->diffForHumans() ?? '—'])">
            <div class="grid gap-6 p-4 sm:p-6">
                @if ($servers->isEmpty())
                    <x-signal.ui.alert tone="info">{{ __('Create or import a server here first; sites move onto it.') }}</x-signal.ui.alert>
                @endif
                @forelse ($move->inventory as $source)
                    <div class="grid gap-3">
                        <h3 class="text-sm font-bold text-ink">{{ $source['name'] }} @if ($source['ip'])<span class="font-mono text-xs font-normal text-muted">{{ $source['ip'] }}</span>@endif</h3>
                        @forelse ($source['sites'] as $site)
                            @php($movedId = ($move->moved ?? [])[$site['key']] ?? null)
                            @php($moved = $movedId !== null ? $websites->get($movedId) : null)
                            <div class="grid gap-3 rounded-lg border border-line p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="font-bold text-ink">{{ $site['domain'] }}</p>
                                        <p class="text-xs text-muted">
                                            {{ $site['repository'] ? $site['repository'].($site['branch'] ? ' @ '.$site['branch'] : '') : __('No repository') }}
                                            @if ($site['php']) · {{ $site['php'] }}@endif
                                            · {{ trans_choice(':count cron job|:count cron jobs', collect($source['crons'])->filter(fn ($cron) => str_contains($cron['command'], $site['root']))->count()) }}
                                            · {{ trans_choice(':count daemon|:count daemons', collect($source['daemons'])->filter(fn ($daemon) => str_contains($daemon['command'], $site['root']) || str_starts_with((string) $daemon['directory'], $site['root']))->count()) }}
                                            · {{ $site['env'] !== '' ? __('environment file read') : __('no environment file') }}
                                        </p>
                                    </div>
                                    @if ($moved !== null)
                                        <x-signal.ui.link :href="route('infrastructure.websites.show', [$project, $moved->id])">{{ __('Moved: open the website') }}</x-signal.ui.link>
                                    @elseif ($servers->isNotEmpty())
                                        <form method="POST" action="{{ route('infrastructure.moves.sites', [$project, $move->id]) }}" class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <input type="hidden" name="site" value="{{ $site['key'] }}">
                                            <x-signal.ui.select-field :id="'move-server-'.$site['key']" name="server_id" :label="__('Server here')">
                                                @foreach ($servers as $server)
                                                    <option value="{{ $server->id }}">{{ $server->label() }}</option>
                                                @endforeach
                                            </x-signal.ui.select-field>
                                            <x-signal.ui.button type="submit" variant="secondary">{{ __('Move') }}</x-signal.ui.button>
                                        </form>
                                    @endif
                                </div>
                                @if ($site['deploy_script'] !== '')
                                    <x-signal.ui.disclosure :title="__('Deploy script in :tool', ['tool' => $move->sourceName()])">
                                        <x-signal.ui.code-block :code="$site['deploy_script']" />
                                        <p class="mt-2 text-xs text-muted">{{ __('Deploy runs Composer, migrations and caching for Laravel apps itself; add anything else as deploy hooks on the environment.') }}</p>
                                    </x-signal.ui.disclosure>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No sites on this server.') }}</p>
                        @endforelse
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No servers were found with that token.') }}</p>
                @endforelse

                <x-signal.ui.disclosure :title="__('After moving a site')" :open="true">
                    <ol class="grid list-decimal gap-2 pl-5 text-sm text-ink">
                        <li>{{ __('Connect its repository and branch in Deploy, and deploy it once to the new website.') }}</li>
                        <li>{{ __('Copy the database: dump it on the old server (mysqldump or pg_dump) and load it on the new one, or restore a backup there.') }}</li>
                        <li>{{ __('Copy uploaded files that aren’t in Git, such as storage/app, with rsync.') }}</li>
                        <li>{{ __('Lower the DNS TTL, then point the domain at the new server. The certificate is issued as soon as it resolves.') }}</li>
                        <li>{{ __('Once traffic has moved, remove the site from :tool.', ['tool' => $move->sourceName()]) }}</li>
                    </ol>
                </x-signal.ui.disclosure>

                <form method="POST" action="{{ route('infrastructure.moves.destroy', [$project, $move->id]) }}" class="flex flex-wrap gap-3">
                    @csrf
                    @method('DELETE')
                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Forget the token and what was read') }}</x-signal.ui.button>
                </form>
            </div>
        </x-signal.ui.settings-section>
    @endforeach
</x-signal.layouts.project>
