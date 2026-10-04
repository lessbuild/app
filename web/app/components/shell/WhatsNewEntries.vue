<script setup lang="ts">
/** The latest changelog entries inside the "What's new" dialog, loaded when it opens, and "Got it". */
type Entry = { date: string; title: string; changes: string[] };
const emit = defineEmits<{ done: [] }>();
const { t, date } = useT();
const entries = ref<Entry[] | null>(null);
const busy = ref(false);
onMounted(async () => {
    entries.value = (await send<{ entries: Entry[] }>('GET', '/whats-new').catch(() => ({ entries: [] }))).entries;
});

/** Mark the changelog seen, clear the dot and close. */
async function seen() {
    busy.value = true;
    await send('POST', '/whats-new/seen').catch(() => null);
    await refreshShell();
    busy.value = false;
    emit('done');
}
</script>

<template>
    <div class="grid gap-5">
        <p v-if="entries === null" class="text-sm text-muted" role="status">{{ t('Loading…') }}</p>
        <section v-for="entry in entries ?? []" :key="`${entry.date}-${entry.title}`" class="grid gap-2">
            <p class="text-xs font-semibold text-subtle"><time :datetime="entry.date">{{ date(entry.date) }}</time></p>
            <h3 class="font-extrabold text-ink">{{ entry.title }}</h3>
            <ul class="grid gap-1.5 text-sm text-muted">
                <li v-for="change in entry.changes" :key="change" class="flex gap-2"><Icon name="check" class="mt-1 size-4 shrink-0 text-success" /><span>{{ change }}</span></li>
            </ul>
        </section>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
            <div class="flex gap-3 text-sm font-bold">
                <NuxtLink to="/changelog" class="text-primary underline">{{ t('Full changelog') }}</NuxtLink>
                <NuxtLink to="/roadmap" class="text-primary underline">{{ t('What’s coming') }}</NuxtLink>
            </div>
            <UiButton variant="primary" size="sm" :disabled="busy" @click="seen">{{ t('Got it') }}</UiButton>
        </div>
    </div>
</template>
