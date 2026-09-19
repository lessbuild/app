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
        <input type="hidden" name="_database_credential_resource" value="{{ $resource->id }}">
        <label class="block" for="{{ $fieldPrefix }}username">
            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Username') }}</span>
            <input id="{{ $fieldPrefix }}username" name="username" value="{{ old('username') }}" class="input secondary w-full rounded-lg" placeholder="report_reader" required>
            <x-forms.errors name="username" />
        </label>
        <label class="block" for="{{ $fieldPrefix }}privilege">
            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Privilege') }}</span>
            <select id="{{ $fieldPrefix }}privilege" name="privilege" class="input secondary w-full rounded-lg">
                <option value="read" @selected(old('privilege', 'read') === 'read')>{{ __('Read only') }}</option>
                <option value="write" @selected(old('privilege') === 'write')>{{ __('Read/write') }}</option>
                <option value="admin" @selected(old('privilege') === 'admin')>{{ __('Admin') }}</option>
            </select>
            <x-forms.errors name="privilege" />
        </label>
        <label class="block" for="{{ $fieldPrefix }}expires-in-days">
            <span class="mb-1 block text-xs font-semibold uppercase text-secondary">{{ __('Expires') }}</span>
            <select id="{{ $fieldPrefix }}expires-in-days" name="expires_in_days" class="input secondary w-full rounded-lg">
                <option value="" @selected(old('expires_in_days') === null || old('expires_in_days') === '')>{{ __('Never expires') }}</option>
                <option value="1" @selected((string) old('expires_in_days') === '1')>1 day</option>
                <option value="7" @selected((string) old('expires_in_days') === '7')>7 days</option>
                <option value="30" @selected((string) old('expires_in_days') === '30')>30 days</option>
                <option value="90" @selected((string) old('expires_in_days') === '90')>90 days</option>
            </select>
            <x-forms.errors name="expires_in_days" />
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Create credential') }}</x-ui.button>
    </form>
</x-dialogs.modal>
