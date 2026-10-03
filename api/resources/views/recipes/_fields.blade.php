@php($recipe ??= null)
<x-signal.ui.input-field name="name" :label="__('Name')" :value="old('name', $recipe?->name)" maxlength="120" required />
<x-signal.ui.select-field name="category" :label="__('Category')">
    @foreach ($categories as $category)
        <option value="{{ $category->value }}" @selected(old('category', $recipe?->category->value ?? 'utilities') === $category->value)>{{ $category->label() }}</option>
    @endforeach
</x-signal.ui.select-field>
<div class="sm:col-span-2"><x-signal.ui.input-field name="description" :label="__('What it does (optional)')" :value="old('description', $recipe?->description)" maxlength="1000" /></div>
<div class="sm:col-span-2"><x-signal.ui.textarea-field name="script" :label="__('Bash script')" rows="14" class="font-mono text-xs" :value="old('script', $recipe?->script)" :description="__('Runs as root at the end of a new server’s provisioning. Make it safe to run once on a fresh Ubuntu server.')" required /></div>
