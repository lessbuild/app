<script setup lang="ts">
/** A release's notes, grouped as the API groups commits: features, fixes, performance, other changes. */
defineProps<{ sections: Record<string, string[]> }>();
const { t } = useT();
const titles = computed<Record<string, string>>(() => ({ Features: t('Features'), Fixes: t('Fixes'), Performance: t('Performance'), 'Other changes': t('Other changes') }));
</script>

<template>
    <div class="grid gap-3">
        <div v-for="(notes, section) in sections" :key="section">
            <h3 class="text-xs font-bold uppercase tracking-wide text-muted">{{ titles[section] ?? section }}</h3>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-ink">
                <li v-for="(note, index) in notes" :key="index">{{ note }}</li>
            </ul>
        </div>
    </div>
</template>
