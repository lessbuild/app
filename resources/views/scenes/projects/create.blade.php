<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('projects.index')" :title="__('Back to applications')" />
    <form method="POST" action="{{ route('projects.store') }}" class="mt-6">
        @csrf
        <x-forms.section :title="__('New application')" :description="__('Start from a production-ready template, then customize every runtime setting.')">
            <div class="space-y-6 bg-primary p-6">
                <div>
                    <label class="block" for="project-name">
                        <span class="mb-1 block text-sm font-semibold text-primary">{{ __('Name') }}</span>
                        <input id="project-name" required name="name" value="{{ old('name') }}" class="input secondary rounded-lg" autocomplete="organization" aria-describedby="project-name-help">
                    </label>
                    <p id="project-name-help" class="mt-1 text-xs text-secondary">{{ __('Use a recognizable name for the application and its environments.') }}</p>
                    <x-forms.errors name="name" />
                </div>

                <div>
                    <label class="block" for="project-description">
                        <span class="mb-1 block text-sm font-semibold text-primary">{{ __('Description') }}</span>
                        <textarea id="project-description" name="description" rows="4" class="input secondary rounded-lg">{{ old('description') }}</textarea>
                    </label>
                    <x-forms.errors name="description" />
                </div>

                <fieldset>
                    <legend class="text-sm font-bold text-primary">{{ __('Application template') }}</legend>
                    <p class="mt-1 text-xs leading-5 text-secondary">{{ __('Choose the starting runtime. You can customize environment settings after creation.') }}</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($templates as $value => $template)
                            <label class="group flex cursor-pointer items-start gap-3 rounded-xl border border-primary bg-secondary p-4 transition hover:border-ternary has-[:checked]:border-ternary has-[:checked]:ring-2 has-[:checked]:ring-ternary/30">
                                <input type="radio" name="preset" value="{{ $value }}" class="mt-1" @checked(old('preset', 'laravel') === $value)>
                                <span class="min-w-0">
                                    <strong class="block text-primary">{{ $template->name }}</strong>
                                    <span class="mt-1 block text-xs leading-5 text-secondary">{{ $template->description }}</span>
                                    <span class="mt-2 inline-flex rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold uppercase text-secondary">{{ $template->runtimeType }}</span>
                                    @if($template->serviceTemplate)
                                        <span class="mt-2 block text-xs font-bold text-primary">{{ __('Curated template :version', ['version' => $template->serviceTemplate->version]) }}</span>
                                        <span class="mt-1 block text-xs text-secondary">{{ trans_choice(':count managed resource|:count managed resources', count($template->serviceTemplate->resources), ['count' => count($template->serviceTemplate->resources)]) }} · {{ __(':count readiness checks', ['count' => count($template->serviceTemplate->readinessChecks)]) }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-forms.errors name="preset" />
                </fieldset>
            </div>
            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-end gap-3 bg-tertiary px-6 py-4">
                    <a href="{{ route('projects.index') }}" class="button ghost">{{ __('Cancel') }}</a>
                    <x-ui.button type="submit" variant="primary">{{ __('Create application') }}</x-ui.button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>
</x-layouts.app>
