<script setup lang="ts">
/**
 * An on/off switch (the Acme theme's toggle) that posts like a checkbox: `value` when on, `uncheckedValue` when off.
 * With `submit` it sends its form as soon as it's flipped, for settings that save on their own.
 */
const props = withDefaults(defineProps<{ name: string; label: string; description?: string; id?: string; checked?: boolean; value?: string; uncheckedValue?: string; showLabel?: boolean; submit?: boolean }>(), {
    description: undefined, id: undefined, checked: false, value: '1', uncheckedValue: '0', showLabel: true, submit: false,
});
const on = ref(props.checked);
const button = ref<HTMLButtonElement | null>(null);
const controlId = computed(() => props.id ?? props.name);
watch(() => props.checked, (value) => (on.value = value));

/** Flip the switch, and send its form when it saves on its own. */
function flip() {
    on.value = !on.value;
    if (props.submit) {
        nextTick(() => button.value?.form?.requestSubmit());
    }
}
</script>

<template>
    <div class="grid gap-1">
        <input type="hidden" :name="name" :value="on ? value : uncheckedValue">
        <span class="inline-flex items-center gap-3">
            <button :id="controlId" ref="button" type="button" role="switch" :aria-checked="on" :aria-label="showLabel ? undefined : label" :aria-describedby="description ? `${controlId}-help` : undefined" :class="['relative h-6 w-11 shrink-0 rounded-full transition-colors', on ? 'bg-accent' : 'bg-zinc-300 dark:bg-white/20']" @click="flip">
                <span :class="['absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition-transform', on && 'translate-x-5']" />
            </button>
            <label v-if="showLabel" :for="controlId" class="cursor-pointer text-sm text-ink" @click.prevent="flip">{{ label }}</label>
        </span>
        <p v-if="description" :id="`${controlId}-help`" class="ui-help">{{ description }}</p>
    </div>
</template>
