@props(['open' => false, 'projects' => collect()])

@php($hasProjectScopeErrors = $errors->has('project_ids') || collect($errors->keys())->contains(fn (string $key): bool => str_starts_with($key, 'project_ids.')))

<x-signal.overlays.modal
    id="automation-token-dialog"
    :title="__('Create personal access token')"
    :description="__('Each token is bound to this workspace. You can optionally limit it to selected projects.')"
    :open="$open"
>
    <form method="POST" action="{{ route('automation.tokens.store') }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_automation_token_form" value="1" :restore="false" />
        <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
            <x-signal.ui.input-field
                id="automation-token-name"
                name="name"
                :label="__('Token name')"
                maxlength="100"
                placeholder="CI deployment"
                required
                class="w-full"
            />
            <x-signal.ui.select-field
                id="automation-token-expires-in-days"
                name="expires_in_days"
                :label="__('Expires')"
                class="w-full"
            >
                    <option value="30" @selected((string) old('expires_in_days', 365) === '30')>{{ __('30 days') }}</option>
                    <option value="90" @selected((string) old('expires_in_days', 365) === '90')>{{ __('90 days') }}</option>
                    <option value="180" @selected((string) old('expires_in_days', 365) === '180')>{{ __('180 days') }}</option>
                    <option value="365" @selected((string) old('expires_in_days', 365) === '365')>{{ __('1 year') }}</option>
            </x-signal.ui.select-field>
        </div>
        <x-signal.ui.card
            as="fieldset"
            tone="muted"
            class="p-4"
            :shadow="false"
            :aria-invalid="$errors->has('abilities') ? 'true' : 'false'"
            :aria-describedby="$errors->has('abilities') ? 'automation-token-abilities-error' : null"
        >
            <legend class="ui-eyebrow">{{ __('Abilities') }}</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                @foreach (['read', 'deploy', 'manage'] as $ability)
                    <x-signal.ui.checkbox
                        :id="'automation-token-ability-'.$ability"
                        name="abilities[]"
                        :value="$ability"
                        :checked="in_array($ability, (array) old('abilities', ['read']), true)"
                        :restore="false"
                        :error-key="false"
                        :show-errors="false"
                    >
                        {{ ucfirst($ability) }}
                    </x-signal.ui.checkbox>
                @endforeach
            </div>
            <x-forms.errors name="abilities" id="automation-token-abilities-error" />
        </x-signal.ui.card>
        @if ($projects->isNotEmpty())
            <x-signal.ui.card
                as="fieldset"
                tone="muted"
                class="p-4"
                :shadow="false"
                :aria-invalid="$hasProjectScopeErrors ? 'true' : 'false'"
                :aria-describedby="$hasProjectScopeErrors ? 'automation-token-projects-error' : null"
            >
                <legend class="ui-eyebrow">{{ __('Limit to projects (optional)') }}</legend>
                <p class="mt-2 text-xs leading-5 text-muted">{{ __('With no project selected, the token can access projects in this workspace that its abilities and your access permit.') }}</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($projects as $project)
                        <x-signal.ui.checkbox
                            :id="'automation-token-project-'.$project->id"
                            name="project_ids[]"
                            :value="$project->id"
                            :checked="in_array((string) $project->id, array_map('strval', (array) old('project_ids', [])), true)"
                            :restore="false"
                            :error-key="false"
                            :show-errors="false"
                        >
                            {{ $project->name }}
                        </x-signal.ui.checkbox>
                    @endforeach
                </div>
                @if ($hasProjectScopeErrors)
                    <p id="automation-token-projects-error" class="mt-2 text-sm text-danger" role="alert">{{ $errors->first('project_ids') ?: $errors->first('project_ids.0') }}</p>
                @endif
            </x-signal.ui.card>
        @endif
        <x-signal.ui.button type="submit" variant="primary">{{ __('Create token') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
