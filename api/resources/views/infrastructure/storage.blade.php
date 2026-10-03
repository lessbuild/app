@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Storage')" :description="__('S3-compatible buckets for uploads, media and exports. Attach one to an environment and Laravel’s s3 disk uses it after the next deploy.')">
    @if ($canManage)
        <x-slot:actions>
            <x-signal.ui.button :href="route('infrastructure.storage', [$project, 'dialog' => 'add-bucket'])" variant="primary" data-modal-trigger="add-bucket">{{ __('Add a bucket') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.form-modal id="add-bucket" :title="__('Add a bucket')" :description="__('Create a new bucket with your storage keys, or add one you already have. The keys are stored encrypted.')" :action="route('infrastructure.storage.store', $project)" :submit="__('Add bucket')" form-class="grid items-start gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2"><x-signal.ui.input-field name="name" :label="__('Name')" placeholder="Uploads" maxlength="60" required /></div>
            <x-signal.ui.select-field name="storage_provider" :label="__('Storage service')">
                @foreach ($presets as $key => $preset)
                    <option value="{{ $key }}" @selected(old('storage_provider') === $key)>{{ $preset['name'] }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.input-field name="region" :label="__('Region')" placeholder="fra1, eu-central-1 or auto" maxlength="60" required />
            <div class="sm:col-span-2"><x-signal.ui.input-field name="endpoint" type="url" :label="__('Endpoint')" placeholder="https://<account-id>.r2.cloudflarestorage.com" :description="__('Filled in for Spaces and Amazon S3 from the region; needed for R2 and others.')" maxlength="255" /></div>
            <x-signal.ui.input-field name="bucket" :label="__('Bucket name')" placeholder="acme-uploads" maxlength="63" required />
            <x-signal.ui.checkbox name="create" :description="__('Leave off to add a bucket that already exists.')">{{ __('Create it now') }}</x-signal.ui.checkbox>
            <x-signal.ui.input-field name="access_key" :label="__('Access key')" maxlength="255" autocomplete="off" required />
            <x-signal.ui.input-field name="secret_key" type="password" :label="__('Secret key')" maxlength="255" autocomplete="new-password" required />
        </x-signal.overlays.form-modal>
    @endif

    @error('bucket')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @forelse ($buckets as $bucket)
        <x-signal.ui.card as="section" class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center" :aria-label="$bucket->name">
            <div class="min-w-0">
                <p class="font-bold text-ink">{{ $bucket->name }} <span class="font-mono text-sm font-normal text-muted">{{ $bucket->bucket }}</span></p>
                <p class="text-sm text-muted">{{ $presets[$bucket->storage_provider]['name'] ?? $bucket->storage_provider }} · {{ $bucket->region }} · <span class="font-mono text-xs">{{ $bucket->endpoint }}</span></p>
                <p class="text-xs text-muted">{{ $bucket->environment ? __('Attached to :environment', ['environment' => $bucket->environment->name]) : __('Not attached to an environment yet') }}</p>
            </div>
            @if ($canManage)
                <div class="flex flex-wrap items-end gap-2">
                    <form method="POST" action="{{ route('infrastructure.storage.attach', [$project, $bucket->id]) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <x-signal.ui.select-field :id="'attach-'.$bucket->id" name="environment_id" :label="__('Environment')" :show-errors="false">
                            @foreach ($environments as $environment)
                                <option value="{{ $environment->id }}" @selected($bucket->environment_id === $environment->id)>{{ $environment->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Attach') }}</x-signal.ui.button>
                    </form>
                    <form method="POST" action="{{ route('infrastructure.storage.destroy', [$project, $bucket->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                </div>
            @endif
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="database" :title="__('No buckets yet')" :description="__('Keep uploads and media in object storage instead of on a server, so every replica and server sees the same files.')" />
    @endforelse
</x-signal.layouts.project>
