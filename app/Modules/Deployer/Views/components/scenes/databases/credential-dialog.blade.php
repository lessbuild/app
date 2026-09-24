@props([
    'resource',
    'open' => false,
])

@php
    $dialogId = 'database-credential-'.$resource->id;
    $fieldPrefix = $dialogId.'-';
@endphp

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Issue a database credential')"
    :description="__('Credentials can be limited by privilege and expiration.')"
    :open="$open"
>
    <form method="POST" action="{{ route('databases.users.store', $resource) }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_database_credential_resource" value="{{ $resource->id }}" :restore="false" />
        <label class="block" for="{{ $fieldPrefix }}username">
            <span class="ui-label">{{ __('Username') }}</span>
            <x-signal.ui.input id="{{ $fieldPrefix }}username" name="username" value="{{ old('username') }}" class="ui-input w-full" placeholder="report_reader" required :restore="false" />
            <x-forms.errors name="username" />
        </label>
        <label class="block" for="{{ $fieldPrefix }}privilege">
            <span class="ui-label">{{ __('Privilege') }}</span>
            <x-signal.ui.select id="{{ $fieldPrefix }}privilege" name="privilege" class="ui-input w-full">
                <option value="read" @selected(old('privilege', 'read') === 'read')>{{ __('Read only') }}</option>
                <option value="write" @selected(old('privilege') === 'write')>{{ __('Read/write') }}</option>
                <option value="admin" @selected(old('privilege') === 'admin')>{{ __('Admin') }}</option>
            </x-signal.ui.select>
            <x-forms.errors name="privilege" />
        </label>
        <label class="block" for="{{ $fieldPrefix }}expires-in-days">
            <span class="ui-label">{{ __('Expires') }}</span>
            <x-signal.ui.select id="{{ $fieldPrefix }}expires-in-days" name="expires_in_days" class="ui-input w-full">
                <option value="" @selected(old('expires_in_days') === null || old('expires_in_days') === '')>{{ __('Never expires') }}</option>
                <option value="1" @selected((string) old('expires_in_days') === '1')>1 day</option>
                <option value="7" @selected((string) old('expires_in_days') === '7')>7 days</option>
                <option value="30" @selected((string) old('expires_in_days') === '30')>30 days</option>
                <option value="90" @selected((string) old('expires_in_days') === '90')>90 days</option>
            </x-signal.ui.select>
            <x-forms.errors name="expires_in_days" />
        </label>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Create credential') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
