<script setup lang="ts">
import type { WebsiteFiles } from '~/types/infrastructure';

/**
 * Browse a website's folder on its server, read the end of a text file and search its Laravel logs, read-only. Each
 * step asks the server, so it loads when the tab opens rather than with the page.
 */
const props = defineProps<{ base: string }>();
const { t, tc, dateTime } = useT();
const bytes = useFileSize();
const files = ref<WebsiteFiles | null>(null);
const failed = ref(false);
const loading = ref(false);
const phrase = ref('');

/** Ask the server for a folder, a file or a search. */
async function open(query: Record<string, string>) {
    loading.value = true;
    failed.value = false;
    try {
        files.value = await send<WebsiteFiles>('GET', `${props.base}/files?${new URLSearchParams(query)}`);
    } catch {
        failed.value = true;
    }
    loading.value = false;
}

const crumbs = computed(() => {
    const path = files.value?.file ?? files.value?.path ?? '';
    const parts = path.split('/').filter(Boolean);
    return parts.map((name, index) => ({ name, path: parts.slice(0, index + 1).join('/') }));
});
const join = (name: string) => [files.value?.path, name].filter(Boolean).join('/');
const parent = (path: string) => path.split('/').slice(0, -1).join('/');
onMounted(() => open({ path: '' }));
</script>

<template>
    <SettingsSection :title="t('Files')" :description="t('Browse the website’s folder on its server, read the end of any text file, and search its Laravel logs. Read-only.')">
        <div class="grid gap-4 p-4 sm:p-6" :aria-busy="loading || undefined">
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="phrase.trim() && open({ q: phrase.trim() })">
                <InputField v-model="phrase" name="q" :label="t('Search the Laravel logs')" placeholder="SQLSTATE" maxlength="200" class="min-w-60" />
                <UiButton type="submit" size="sm">{{ t('Search') }}</UiButton>
            </form>
            <nav v-if="files" class="flex flex-wrap items-center gap-1 text-sm" :aria-label="t('Folders')">
                <button type="button" class="font-mono text-primary hover:underline" @click="open({ path: '' })">/</button>
                <template v-for="crumb in crumbs" :key="crumb.path">
                    <Icon name="chevron-right" class="h-3 w-3 text-muted" />
                    <button type="button" class="font-mono text-primary hover:underline" @click="open({ path: crumb.path })">{{ crumb.name }}</button>
                </template>
            </nav>
            <p v-if="loading && !files" class="text-sm text-muted" role="status">{{ t('Reading the folder…') }}</p>
            <Alert v-else-if="failed" tone="danger">{{ t('Couldn’t reach the server.') }}</Alert>
            <template v-else-if="files">
                <template v-if="files.folder">
                    <Alert v-if="files.folder.error" tone="danger">{{ files.folder.error }}</Alert>
                    <ul v-else class="divide-y divide-line rounded-panel border border-line text-sm">
                        <li v-if="files.path" class="px-3 py-2"><button type="button" class="font-mono text-primary hover:underline" @click="open({ path: parent(files.path) })">..</button></li>
                        <li v-for="entry in files.folder.entries" :key="entry.name" class="flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                            <button v-if="entry.type === 'folder' || entry.type === 'link'" type="button" class="font-mono text-primary hover:underline" @click="open({ path: join(entry.name) })">
                                {{ entry.name }}{{ entry.type === 'folder' ? '/' : ' →' }}
                            </button>
                            <button v-else type="button" class="font-mono text-ink hover:underline" @click="open({ file: join(entry.name) })">{{ entry.name }}</button>
                            <span class="text-xs text-muted">{{ entry.type === 'file' ? bytes(entry.size) : '' }} · {{ dateTime(new Date(entry.modified * 1000).toISOString()) }}</span>
                        </li>
                        <li v-if="files.folder.entries.length === 0" class="px-3 py-2 text-muted">{{ t('Empty folder.') }}</li>
                    </ul>
                </template>
                <template v-else-if="files.tail">
                    <Alert v-if="files.tail.error" tone="danger">{{ files.tail.error }}</Alert>
                    <CodeBlock v-else :code="files.tail.content" class="max-h-[32rem] overflow-auto whitespace-pre-wrap text-xs" />
                </template>
                <template v-else-if="files.search">
                    <Alert v-if="files.search.error" tone="danger">{{ files.search.error }}</Alert>
                    <template v-else>
                        <p class="text-sm text-muted">{{ tc(':count match|:count matches', files.search.matches.length, { count: files.search.matches.length }) }}</p>
                        <ul class="grid gap-2 text-xs">
                            <li v-for="(match, index) in files.search.matches" :key="index" class="rounded-control bg-surface-muted p-2">
                                <span class="font-mono font-bold">{{ match.file.split('/').pop() }}:{{ match.line }}</span>
                                <span class="block break-words font-mono text-muted">{{ match.text }}</span>
                            </li>
                        </ul>
                    </template>
                </template>
            </template>
        </div>
    </SettingsSection>
</template>
