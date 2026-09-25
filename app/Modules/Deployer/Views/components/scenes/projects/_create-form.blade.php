<div class="space-y-6 bg-surface px-5 py-5 sm:px-8">
    <div>
        <label class="block" for="project-name">
            <span class="ui-label">{{ __('Name') }}</span>
            <x-signal.ui.input id="project-name" required name="name" value="{{ old('name') }}" class="ui-input" autocomplete="organization" aria-describedby="project-name-help" :restore="false" />
        </label>
        <p id="project-name-help" class="mt-1 text-xs text-muted">{{ __('Use a recognizable name for the application and its environments.') }}</p>
        <x-forms.errors name="name" />
    </div>

    <div>
        <label class="block" for="project-description">
            <span class="ui-label">{{ __('Description') }}</span>
            <x-signal.ui.textarea id="project-description" name="description" rows="4" class="ui-input min-h-28" :restore="false">{{ old('description') }}</x-signal.ui.textarea>
        </label>
        <x-forms.errors name="description" />
    </div>

    <fieldset>
        <legend class="text-sm font-bold text-ink">{{ __('Application template') }}</legend>
        <p class="mt-1 text-xs leading-5 text-muted">{{ __('Choose the starting runtime. You can customize environment settings after creation.') }}</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($templates as $value => $template)
                <label class="ui-choice group">
                    <x-signal.ui.input type="radio" name="preset" value="{{ $value }}" class="ui-check mt-1" @checked(old('preset', 'laravel') === $value) :restore="false" />
                    <span class="min-w-0">
                        <strong class="block text-ink">{{ $template->name }}</strong>
                        <span class="mt-1 block text-xs leading-5 text-muted">{{ $template->description }}</span>
                        <x-signal.ui.badge tone="neutral" class="mt-2">{{ $template->runtimeType }}</x-signal.ui.badge>
                        @if($template->serviceTemplate)
                            <span class="mt-2 block text-xs font-bold text-ink">{{ __('Curated template :version', ['version' => $template->serviceTemplate->version]) }}</span>
                            <span class="mt-1 block text-xs text-muted">{{ trans_choice(':count managed resource|:count managed resources', count($template->serviceTemplate->resources), ['count' => count($template->serviceTemplate->resources)]) }} · {{ __(':count readiness checks', ['count' => count($template->serviceTemplate->readinessChecks)]) }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
        <x-forms.errors name="preset" />
    </fieldset>
</div>
