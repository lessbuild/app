{{-- A firewall rule's fields; $rule is null for a new one. --}}
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-name'" name="name" :label="__('Name')" :value="$rule?->name" placeholder="Meilisearch" maxlength="60" required /></div>
<x-signal.ui.input-field :id="$prefix.'-port'" name="port" :label="__('Port or range')" :value="$rule?->port" placeholder="7700" maxlength="11" required />
<x-signal.ui.select-field :id="$prefix.'-protocol'" name="protocol" :label="__('Protocol')">
    <option value="tcp" @selected(($rule?->protocol ?? 'tcp') === 'tcp')>TCP</option>
    <option value="udp" @selected($rule?->protocol === 'udp')>UDP</option>
</x-signal.ui.select-field>
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-source'" name="source" :label="__('Only from (optional)')" :value="$rule?->source" placeholder="203.0.113.10 or 10.0.0.0/16" :description="__('Leave empty to allow anyone.')" maxlength="43" /></div>
