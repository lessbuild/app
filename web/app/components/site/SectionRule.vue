<script setup lang="ts">
/** A full-width numbered divider between the homepage's sections: the number, a violet label and a quiet note. */
defineProps<{ number: number; label: string; note?: string }>();
</script>

<template>
    <div class="border-b border-line font-mono text-[0.6875rem] uppercase tracking-wider" aria-hidden="true">
        <div class="mx-auto flex h-9 max-w-6xl items-center gap-4 border-x border-line px-4">
            <span class="text-muted">{{ String(number).padStart(2, '0') }}</span>
            <span class="font-semibold text-primary">{{ label }}</span>
            <span v-if="note" class="ml-auto hidden truncate normal-case tracking-normal text-muted sm:block">{{ note }}</span>
        </div>
    </div>
</template>
