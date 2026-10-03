<script setup lang="ts">
/** The events a webhook endpoint receives: everything (including events added later), or chosen ones by group. */
defineProps<{
    prefix: string;
    groups: Array<{ group: string; events: Array<{ event: string; meaning: string }> }>;
    selected?: string[];
}>();
const { t } = useT();
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="text-sm font-bold text-ink">{{ t('Events') }}</legend>
        <label class="inline-flex items-center gap-2 text-sm font-semibold text-ink">
            <input :id="`${prefix}-all`" type="checkbox" class="ui-check" name="events[]" value="*" :checked="selected?.includes('*')">
            {{ t('Everything, including events added later') }}
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="group in groups" :key="group.group" class="grid content-start gap-2">
                <p class="text-xs font-bold uppercase tracking-wide text-muted">{{ group.group }}</p>
                <label v-for="item in group.events" :key="item.event" class="flex items-start gap-2 text-sm">
                    <input :id="`${prefix}-${item.event.replaceAll('.', '-')}`" type="checkbox" class="ui-check mt-0.5" name="events[]" :value="item.event" :checked="selected?.includes(item.event)">
                    <span>
                        <span class="block font-mono text-xs text-ink">{{ item.event }}</span>
                        <span class="block text-xs text-muted">{{ item.meaning }}</span>
                    </span>
                </label>
            </div>
        </div>
        <FieldError name="events" />
    </fieldset>
</template>
