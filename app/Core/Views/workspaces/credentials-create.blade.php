<x-signal.layouts.platform :title="__('Create credential')" :description="__('Issue a credential for a mapped product resource.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Create credential')" :description="__('Create a product-owned credential for a mapped workspace resource.')" />

    @if ($errors->any())
        <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert>
    @endif

    @if ($sourceUnavailable)
        <x-signal.ui.alert tone="warning" class="mt-6">{{ __('A credential source is unavailable. Retry after the owning product database responds.') }}</x-signal.ui.alert>
    @endif

    @if ($options->isEmpty())
        <x-signal.ui.empty-state class="mt-6" :title="__('No credential targets are available')" :description="__('Check product access, owner permissions, and active project resource mappings.')" />
    @else
        <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.credentials.store', $workspace)" class="mt-6 space-y-5 p-5">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <x-signal.ui.select-field :label="__('Credential target')" name="target_option" id="credential-target" required>
                    @foreach ($options as $index => $option)
                        <option value="{{ $index }}" data-product="{{ $option->product }}" data-type="{{ $option->type }}" data-target="{{ $option->targetKey }}" data-name-required="{{ $option->requiresName ? '1' : '0' }}" data-expiry="{{ $option->supportsExpiry ? '1' : '0' }}" data-default-expiry="{{ $option->defaultExpiryDays ?? '' }}" data-permissions="{{ implode(',', $option->permissions) }}" data-scopes="{{ implode(',', array_column($option->scopes, 'key')) }}">
                            {{ $option->label }} · {{ $option->scope }}
                        </option>
                    @endforeach
            </x-signal.ui.select-field>
            <input type="hidden" name="product" id="credential-product" value="{{ $options->first()->product }}">
            <input type="hidden" name="credential_type" id="credential-type" value="{{ $options->first()->type }}">
            <input type="hidden" name="target_key" id="credential-target-key" value="{{ $options->first()->targetKey }}">

            <div data-field="name">
                <x-signal.ui.input-field name="name" :label="__('Name')" maxlength="100" autocomplete="off" :restore="false" />
            </div>
            <div data-field="expiry">
                <x-signal.ui.select-field name="expires_in_days" :label="__('Expires in days')">
                    <option value="" data-no-expiration>{{ __('No expiration') }}</option>
                    @foreach ([30, 90, 180, 365] as $days)<option value="{{ $days }}">{{ $days }}</option>@endforeach
                </x-signal.ui.select-field>
            </div>
            <fieldset class="space-y-2" data-field="permissions">
                <legend class="text-sm font-semibold">{{ __('Permissions') }}</legend>
                @foreach (['read', 'deploy', 'manage'] as $permission)
                    <div data-permission="{{ $permission }}"><x-signal.ui.checkbox name="permissions[]" :id="'credential-permission-'.$permission" :value="$permission" :checked="$permission === 'read'" :restore="false">{{ __(ucfirst($permission)) }}</x-signal.ui.checkbox></div>
                @endforeach
            </fieldset>
            <fieldset class="space-y-2" data-field="projects">
                <legend class="text-sm font-semibold">{{ __('Restrict to projects') }}</legend>
                @foreach ($options->flatMap(fn ($option) => $option->scopes)->unique('key') as $scope)
                    <div data-project="{{ $scope['key'] }}"><x-signal.ui.checkbox name="canonical_project_ids[]" :id="'credential-project-'.$scope['key']" :value="$scope['key']" :restore="false">{{ $scope['label'] }}</x-signal.ui.checkbox></div>
                @endforeach
                <p class="text-sm text-muted">{{ __('Leave all projects unchecked for workspace-wide access. Requests still require the token owner’s current project permissions.') }}</p>
            </fieldset>
            <div class="flex justify-end gap-3">
                <x-signal.ui.button :href="route('core.workspace.credentials', $workspace)" variant="ghost">{{ __('Cancel') }}</x-signal.ui.button>
                <x-signal.ui.button type="submit">{{ __('Create credential') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.card>
        <script>
            (() => {
                const select = document.getElementById('credential-target');
                const update = () => {
                    const option = select.options[select.selectedIndex];
                    document.getElementById('credential-product').value = option.dataset.product;
                    document.getElementById('credential-type').value = option.dataset.type;
                    document.getElementById('credential-target-key').value = option.dataset.target;
                    const name = document.querySelector('[data-field="name"] input');
                    name.required = option.dataset.nameRequired === '1';
                    document.querySelector('[data-field="expiry"]').hidden = option.dataset.expiry !== '1';
                    document.querySelector('[data-field="expiry"] select').disabled = option.dataset.expiry !== '1';
                    document.querySelector('[data-field="expiry"] select').value = option.dataset.defaultExpiry || '';
                    document.querySelector('[data-no-expiration]').hidden = option.dataset.defaultExpiry !== '';
                    document.querySelector('[data-no-expiration]').disabled = option.dataset.defaultExpiry !== '';
                    const permissions = option.dataset.permissions.split(',').filter(Boolean);
                    document.querySelector('[data-field="permissions"]').hidden = permissions.length === 0;
                    document.querySelectorAll('[data-permission]').forEach((label) => {
                        const enabled = permissions.includes(label.dataset.permission);
                        label.hidden = !enabled;
                        label.querySelector('input').disabled = !enabled;
                        if (!enabled) label.querySelector('input').checked = false;
                    });
                    const scopes = option.dataset.scopes.split(',').filter(Boolean);
                    document.querySelector('[data-field="projects"]').hidden = scopes.length === 0;
                    document.querySelectorAll('[data-project]').forEach((label) => {
                        const enabled = scopes.includes(label.dataset.project);
                        label.hidden = !enabled;
                        label.querySelector('input').disabled = !enabled;
                        if (!enabled) label.querySelector('input').checked = false;
                    });
                };
                select.addEventListener('change', update);
                update();
            })();
        </script>
    @endif
</x-signal.layouts.platform>
