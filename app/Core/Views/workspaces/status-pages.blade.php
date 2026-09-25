<x-signal.layouts.platform
    :title="__('Customer status pages')"
    :description="__('Publish customer-facing status and incident updates from the shared workspace.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
>
    <x-signal.ui.page-header
        eyebrow="{{ $workspace->name }} · {{ __('Deployer') }}"
        :title="__('Customer status pages')"
        :description="__('Manage published service pages and incident updates from Core. Status records, subscriber consent, and notification delivery remain owned by Deployer.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="quiet">{{ __('Overview') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (! $management)
        <x-signal.ui.card class="mt-7 p-6">
            <x-signal.ui.empty-state
                :title="__('Deployer status pages are unavailable')"
                :description="__('Core could not verify one active Deployer organization and account mapping for this workspace. Reconcile those mappings before changing product records.')"
                icon="globe-alt"
            />
        </x-signal.ui.card>
    @else
        @if (! $management->featureAvailable)
            <x-signal.ui.alert class="mt-6" tone="warning">
                {{ __('Your current Deployer plan does not include status-page management. Existing published pages and subscriber updates remain available.') }}
                <a href="{{ route('core.workspace.subscriptions', $workspace) }}" class="ui-link font-bold">{{ __('Review app plans') }}</a>
            </x-signal.ui.alert>
        @endif

        @if (session('success'))
            <x-signal.ui.alert class="mt-6" tone="success" role="status">{{ session('success') }}</x-signal.ui.alert>
        @endif

        @if ($errors->any())
            <x-signal.ui.alert class="mt-6" tone="danger" role="alert">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            </x-signal.ui.alert>
        @endif

        <section class="mt-7" aria-labelledby="status-pages-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Public communication') }}</p>
                    <h2 id="status-pages-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Published status pages') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Choose the Deployer websites whose current health and rolling uptime appear on each page.') }}</p>
                </div>
                <x-signal.ui.badge>{{ trans_choice(':count page|:count pages', count($management->pages), ['count' => count($management->pages)]) }}</x-signal.ui.badge>
            </div>

            @if ($management->pages === [])
                <x-signal.ui.card class="p-5">
                    <x-signal.ui.empty-state :title="__('No status pages yet')" :description="__('Create a page to share selected Deployer service health with customers.')" icon="globe-alt" />
                </x-signal.ui.card>
            @endif

            <div class="grid gap-4">
                @foreach ($management->pages as $page)
                    <x-signal.ui.card as="article" class="p-5 sm:p-6" aria-labelledby="status-page-{{ $page['id'] }}-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 id="status-page-{{ $page['id'] }}-heading" class="text-lg font-extrabold text-ink">{{ $page['name'] }}</h3>
                                <p class="mt-1 text-sm text-muted">/{{ $page['slug'] }} · {{ trans_choice(':count confirmed subscriber|:count confirmed subscribers', $page['subscriber_count'], ['count' => $page['subscriber_count']]) }}</p>
                                @if ($page['description'])<p class="mt-2 max-w-3xl text-sm leading-6 text-muted">{{ $page['description'] }}</p>@endif
                            </div>
                            <x-signal.ui.badge :tone="$page['published'] ? 'success' : 'neutral'">{{ $page['published'] ? __('Published') : __('Private') }}</x-signal.ui.badge>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            @if ($page['published'])
                                <x-signal.ui.button :href="$page['public_url']" variant="secondary" target="_blank" rel="noopener noreferrer">{{ __('Open public status page') }}</x-signal.ui.button>
                            @else
                                <span class="text-xs text-muted">{{ __('This page stays private until it is published.') }}</span>
                            @endif
                            <span class="text-xs text-muted">{{ __('Components: :names', ['names' => implode(', ', $page['website_names'])]) }}</span>
                        </div>

                        @if ($management->canManage && $management->featureAvailable)
                            <details class="mt-5 border-t border-line pt-4">
                                <summary class="cursor-pointer text-sm font-bold text-primary focus-visible:outline-2 focus-visible:outline-focus">{{ __('Edit this status page') }}</summary>
                                <form method="POST" action="{{ route('core.workspace.status-pages.update', [$workspace, $page['id']]) }}" class="mt-4 grid gap-4 md:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block">
                                        <span class="ui-label">{{ __('Name') }}</span>
                                        <x-signal.ui.input name="name" value="{{ $page['name'] }}" maxlength="100" required class="ui-input" :restore="false" />
                                    </label>
                                    <label class="block">
                                        <span class="ui-label">{{ __('Description') }}</span>
                                        <x-signal.ui.input name="description" value="{{ $page['description'] }}" maxlength="1000" class="ui-input" :restore="false" />
                                    </label>
                                    <fieldset class="grid gap-2 md:col-span-2 sm:grid-cols-2">
                                        <legend class="ui-label">{{ __('Components') }}</legend>
                                        @foreach ($management->websites as $website)
                                            <label class="ui-choice">
                                                <x-signal.ui.input type="checkbox" name="website_ids[]" value="{{ $website['id'] }}" class="ui-check" @checked(in_array($website['id'], $page['website_ids'], true)) :restore="false" />
                                                <span class="text-sm text-ink">{{ $website['name'] }}</span>
                                            </label>
                                        @endforeach
                                    </fieldset>
                                    <label class="ui-choice md:col-span-2">
                                        <x-signal.ui.input type="hidden" name="is_published" value="0" :restore="false" />
                                        <x-signal.ui.input type="checkbox" name="is_published" value="1" class="ui-check" @checked($page['published']) :restore="false" />
                                        <span class="text-sm text-ink">{{ __('Publish this page publicly') }}</span>
                                    </label>
                                    <div class="flex flex-wrap gap-2 md:col-span-2">
                                        <x-signal.ui.button type="submit" variant="primary">{{ __('Save status page') }}</x-signal.ui.button>
                                    </div>
                                </form>
                                <x-signal.ui.button
                                    :href="route('core.workspace.status-pages.index', $workspace).'#delete-status-page-'.$page['id']"
                                    data-modal-trigger="delete-status-page-{{ $page['id'] }}"
                                    aria-controls="delete-status-page-{{ $page['id'] }}"
                                    aria-expanded="false"
                                    variant="danger"
                                    class="mt-3"
                                >{{ __('Delete status page') }}</x-signal.ui.button>
                                <x-signal.overlays.delete-confirmation
                                    id="delete-status-page-{{ $page['id'] }}"
                                    :route="route('core.workspace.status-pages.destroy', [$workspace, $page['id']])"
                                    :title="__('Delete :page?', ['page' => $page['name']])"
                                    :description="__('This also removes its Deployer incident timeline and subscription records.')"
                                />
                            </details>
                        @endif
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($management->canManage && $management->featureAvailable)
                <x-signal.ui.card as="section" class="mt-5 p-5 sm:p-6" aria-labelledby="create-status-page-heading">
                    <h3 id="create-status-page-heading" class="text-lg font-extrabold text-ink">{{ __('Create a status page') }}</h3>
                    <form method="POST" action="{{ route('core.workspace.status-pages.store', $workspace) }}" class="mt-4 grid gap-4 md:grid-cols-2">
                        @csrf
                        <label class="block">
                            <span class="ui-label">{{ __('Name') }}</span>
                            <x-signal.ui.input name="name" value="{{ old('name') }}" maxlength="100" required class="ui-input" placeholder="{{ __('Application status') }}" />
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Slug') }}</span>
                            <x-signal.ui.input name="slug" value="{{ old('slug') }}" maxlength="100" class="ui-input" placeholder="application-status" />
                        </label>
                        <label class="block md:col-span-2">
                            <span class="ui-label">{{ __('Description') }}</span>
                            <x-signal.ui.textarea name="description" maxlength="1000" class="ui-input">{{ old('description') }}</x-signal.ui.textarea>
                        </label>
                        <fieldset class="grid gap-2 md:col-span-2 sm:grid-cols-2">
                            <legend class="ui-label">{{ __('Websites shown as status components') }}</legend>
                            @forelse ($management->websites as $website)
                                <label class="ui-choice">
                                    <x-signal.ui.input type="checkbox" name="website_ids[]" value="{{ $website['id'] }}" class="ui-check" :restore="false" />
                                    <span class="text-sm text-ink">{{ $website['name'] }}</span>
                                </label>
                            @empty
                                <p class="text-sm text-muted">{{ __('Add a Deployer website before creating a public status page.') }}</p>
                            @endforelse
                        </fieldset>
                        <label class="ui-choice md:col-span-2">
                            <x-signal.ui.input type="hidden" name="is_published" value="0" :restore="false" />
                            <x-signal.ui.input type="checkbox" name="is_published" value="1" class="ui-check" checked :restore="false" />
                            <span class="text-sm text-ink">{{ __('Publish this page publicly') }}</span>
                        </label>
                        <div class="md:col-span-2">
                            <x-signal.ui.button type="submit" variant="primary" :disabled="$management->websites === []">{{ __('Create status page') }}</x-signal.ui.button>
                        </div>
                    </form>
                </x-signal.ui.card>
            @endif
        </section>

        <section class="mt-8" aria-labelledby="status-incidents-heading">
            <div class="mb-4">
                <p class="ui-eyebrow">{{ __('Customer updates') }}</p>
                <h2 id="status-incidents-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Incidents and maintenance') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Status changes keep their Deployer history and notify confirmed subscribers through the existing delivery flow.') }}</p>
            </div>

            @if ($management->incidents === [])
                <x-signal.ui.card class="p-5">
                    <x-signal.ui.empty-state :title="__('No status updates yet')" :description="__('Published incidents and planned-maintenance updates will appear here.')" icon="warning" />
                </x-signal.ui.card>
            @endif

            <div class="grid gap-4">
                @foreach ($management->incidents as $incident)
                    <x-signal.ui.card as="article" class="p-5 sm:p-6" aria-labelledby="status-incident-{{ $incident['id'] }}-heading">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 id="status-incident-{{ $incident['id'] }}-heading" class="font-extrabold text-ink">{{ $incident['title'] }}</h3>
                            <x-signal.ui.badge :tone="in_array($incident['status'], ['resolved', 'completed'], true) ? 'success' : ($incident['severity'] === 'critical' ? 'danger' : 'warning')">{{ str($incident['status'])->headline() }}</x-signal.ui.badge>
                            <x-signal.ui.badge tone="neutral">{{ $incident['page_name'] }} · {{ str($incident['kind'])->headline() }}</x-signal.ui.badge>
                        </div>
                        <p class="mt-1 text-xs text-muted">{{ str($incident['severity'])->headline() }} · {{ __('Started :time UTC', ['time' => $incident['starts_at']]) }}</p>
                        <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-muted">{{ $incident['message'] }}</p>
                        @if ($incident['root_cause'] || $incident['remediation'] || $incident['follow_up'])
                            <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                                @foreach ([[__('Root cause'), $incident['root_cause']], [__('Remediation'), $incident['remediation']], [__('Follow-up'), $incident['follow_up']]] as [$label, $value])
                                    @if ($value)
                                        <div class="rounded-control border border-line bg-surface-muted p-3"><dt class="text-xs font-bold uppercase text-muted">{{ $label }}</dt><dd class="mt-1 whitespace-pre-wrap text-sm text-ink">{{ $value }}</dd></div>
                                    @endif
                                @endforeach
                            </dl>
                        @endif

                        @if ($management->canManage && $management->featureAvailable)
                            <details class="mt-5 border-t border-line pt-4">
                                <summary class="cursor-pointer text-sm font-bold text-primary focus-visible:outline-2 focus-visible:outline-focus">{{ __('Update status and incident review') }}</summary>
                                @php($incidentStatuses = $incident['kind'] === 'incident' ? ['investigating', 'identified', 'monitoring', 'resolved'] : ['scheduled', 'in_progress', 'completed'])
                                <form method="POST" action="{{ route('core.workspace.status-pages.incidents.update', [$workspace, $incident['id']]) }}" class="mt-4 grid gap-4 md:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <x-signal.ui.input type="hidden" name="kind" value="{{ $incident['kind'] }}" :restore="false" />
                                    <label class="block">
                                        <span class="ui-label">{{ __('Status') }}</span>
                                        <x-signal.ui.select name="status" class="ui-input" required>
                                            @foreach ($incidentStatuses as $status)
                                                <option value="{{ $status }}" @selected($incident['status'] === $status)>{{ str($status)->headline() }}</option>
                                            @endforeach
                                        </x-signal.ui.select>
                                    </label>
                                    <label class="block">
                                        <span class="ui-label">{{ __('Severity') }}</span>
                                        <x-signal.ui.select name="severity" class="ui-input" required>
                                            @foreach (['minor', 'major', 'critical'] as $severity)
                                                <option value="{{ $severity }}" @selected($incident['severity'] === $severity)>{{ str($severity)->headline() }}</option>
                                            @endforeach
                                        </x-signal.ui.select>
                                    </label>
                                    <label class="block">
                                        <span class="ui-label">{{ __('Title') }}</span>
                                        <x-signal.ui.input name="title" value="{{ $incident['title'] }}" maxlength="255" required class="ui-input" :restore="false" />
                                    </label>
                                    <label class="block">
                                        <span class="ui-label">{{ __('Starts at (UTC)') }}</span>
                                        <x-signal.ui.input type="datetime-local" name="starts_at" value="{{ $incident['starts_at'] }}" required class="ui-input" :restore="false" />
                                    </label>
                                    <label class="block md:col-span-2">
                                        <span class="ui-label">{{ __('Public message') }}</span>
                                        <x-signal.ui.textarea name="message" maxlength="5000" required class="ui-input">{{ $incident['message'] }}</x-signal.ui.textarea>
                                    </label>
                                    @foreach ([['root_cause', __('Root cause')], ['remediation', __('Remediation')], ['follow_up', __('Follow-up')]] as [$field, $label])
                                        <label class="block">
                                            <span class="ui-label">{{ $label }} · {{ __('Internal review') }}</span>
                                            <x-signal.ui.textarea name="{{ $field }}" maxlength="5000" class="ui-input">{{ $incident[$field] }}</x-signal.ui.textarea>
                                        </label>
                                    @endforeach
                                    <label class="block">
                                        <span class="ui-label">{{ __('Ends at (optional)') }}</span>
                                        <x-signal.ui.input type="datetime-local" name="ends_at" value="{{ $incident['ends_at'] }}" class="ui-input" :restore="false" />
                                    </label>
                                    <div class="md:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save update') }}</x-signal.ui.button></div>
                                </form>
                            </details>
                        @endif
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($management->canManage && $management->featureAvailable && $management->pages !== [])
                <x-signal.ui.card as="section" class="mt-5 p-5 sm:p-6" aria-labelledby="publish-update-heading">
                    <h3 id="publish-update-heading" class="text-lg font-extrabold text-ink">{{ __('Publish an incident or maintenance update') }}</h3>
                    <form method="POST" action="{{ route('core.workspace.status-pages.incidents.store', $workspace) }}" class="mt-4 grid gap-4 md:grid-cols-2" x-data="{ updateKind: 'incident' }">
                        @csrf
                        <label class="block md:col-span-2">
                            <span class="ui-label">{{ __('Status page') }}</span>
                            <x-signal.ui.select name="status_page_id" class="ui-input" required>
                                @foreach ($management->pages as $page)<option value="{{ $page['id'] }}">{{ $page['name'] }}</option>@endforeach
                            </x-signal.ui.select>
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Update type') }}</span>
                            <x-signal.ui.select name="kind" class="ui-input" required x-model="updateKind">
                                <option value="incident">{{ __('Incident') }}</option>
                                <option value="maintenance">{{ __('Planned maintenance') }}</option>
                            </x-signal.ui.select>
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Status') }}</span>
                            <x-signal.ui.select name="status" class="ui-input" required>
                                <option value="investigating" x-show="updateKind === 'incident'">{{ __('Investigating') }}</option>
                                <option value="identified" x-show="updateKind === 'incident'">{{ __('Identified') }}</option>
                                <option value="monitoring" x-show="updateKind === 'incident'">{{ __('Monitoring') }}</option>
                                <option value="resolved" x-show="updateKind === 'incident'">{{ __('Resolved') }}</option>
                                <option value="scheduled" x-show="updateKind === 'maintenance'">{{ __('Scheduled') }}</option>
                                <option value="in_progress" x-show="updateKind === 'maintenance'">{{ __('In Progress') }}</option>
                                <option value="completed" x-show="updateKind === 'maintenance'">{{ __('Completed') }}</option>
                            </x-signal.ui.select>
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Severity') }}</span>
                            <x-signal.ui.select name="severity" class="ui-input" required>
                                @foreach (['minor', 'major', 'critical'] as $severity)<option value="{{ $severity }}">{{ str($severity)->headline() }}</option>@endforeach
                            </x-signal.ui.select>
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Title') }}</span>
                            <x-signal.ui.input name="title" maxlength="255" required class="ui-input" />
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Starts at (UTC)') }}</span>
                            <x-signal.ui.input type="datetime-local" name="starts_at" value="{{ now('UTC')->format('Y-m-d\\TH:i') }}" required class="ui-input" :restore="false" />
                        </label>
                        <label class="block">
                            <span class="ui-label">{{ __('Ends at (optional)') }}</span>
                            <x-signal.ui.input type="datetime-local" name="ends_at" class="ui-input" :restore="false" />
                        </label>
                        <label class="block md:col-span-2">
                            <span class="ui-label">{{ __('Public message') }}</span>
                            <x-signal.ui.textarea name="message" maxlength="5000" required class="ui-input" />
                        </label>
                        @foreach ([['root_cause', __('Root cause')], ['remediation', __('Remediation')], ['follow_up', __('Follow-up')]] as [$field, $label])
                            <label class="block">
                                <span class="ui-label">{{ $label }} · {{ __('Internal review') }}</span>
                                <x-signal.ui.textarea name="{{ $field }}" maxlength="5000" class="ui-input" />
                            </label>
                        @endforeach
                        <div class="md:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Publish update and notify subscribers') }}</x-signal.ui.button></div>
                    </form>
                </x-signal.ui.card>
            @endif
        </section>
    @endif
</x-signal.layouts.platform>
