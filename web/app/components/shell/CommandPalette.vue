<script setup lang="ts">
import type { Shell } from '~/types/shell';

/**
 * Jump anywhere with the keyboard (⌘K or Ctrl+K, or the search button): quick actions, the account's projects, and
 * a search of projects, domains and members as you type.
 */
const props = defineProps<{ shell: Shell }>();
const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const term = ref('');
const active = ref(0);
const groups = ref<Array<{ label: string; results: Array<{ title: string; subtitle?: string | null; url: string }> }>>([]);
let timer: number | undefined;

type Entry = { group: string; title: string; subtitle?: string | null; url: string };

/** What's offered before (and alongside) a search: the main pages and the projects, filtered by what's typed. */
const quick = computed<Entry[]>(() => {
    const actions: Entry[] = [
        ...(props.shell.canCreateProject ? [{ group: t('Actions'), title: t('New project'), url: '/dashboard?dialog=new-project' }] : []),
        { group: t('Actions'), title: t('Projects'), url: '/dashboard' },
        { group: t('Actions'), title: t('Notifications'), url: '/notifications' },
        { group: t('Actions'), title: t('Your settings'), url: '/settings/profile' },
        ...props.shell.accountLinks.map((link) => ({ group: t('Actions'), title: link.label, url: link.url })),
    ];
    const projects = props.shell.projects.map((project) => ({ group: t('Projects'), title: project.name, url: `/projects/${project.id}` }));
    const needle = term.value.trim().toLowerCase();
    return [...actions, ...projects].filter((entry) => !needle || entry.title.toLowerCase().includes(needle));
});

const entries = computed<Entry[]>(() => {
    const found = groups.value.flatMap((group) => group.results.map((result) => ({ group: group.label, title: result.title, subtitle: result.subtitle, url: local(result.url) })));
    const seen = new Set(found.map((entry) => entry.url));
    return [...quick.value.filter((entry) => !seen.has(entry.url)), ...found].slice(0, 30);
});

watch(term, (value) => {
    active.value = 0;
    window.clearTimeout(timer);
    if (value.trim().length < 2) {
        groups.value = [];
        return;
    }
    timer = window.setTimeout(async () => {
        groups.value = (await send<{ groups: typeof groups.value }>('GET', `/search?q=${encodeURIComponent(value.trim())}`).catch(() => ({ groups: [] }))).groups;
    }, 200);
});

function open() {
    term.value = '';
    groups.value = [];
    dialog.value?.showModal();
    nextTick(() => input.value?.focus());
}

function close() {
    dialog.value?.close();
}

async function go(entry: Entry | undefined) {
    if (entry) {
        close();
        await navigateTo(entry.url);
    }
}

function keys(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(entries.value.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(entries.value[active.value]);
    }
}

/** ⌘K / Ctrl+K anywhere opens the palette. */
function shortcut(event: KeyboardEvent) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open();
    }
}
onMounted(() => {
    window.addEventListener('keydown', shortcut);
    // Other parts of the app (the footer's Search) open it with this event.
    window.addEventListener('buildpusher:command-palette', open);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', shortcut);
    window.removeEventListener('buildpusher:command-palette', open);
});
</script>

<template>
    <button type="button" class="ui-icon-btn" :aria-label="t('Search')" :title="t('Search (⌘K)')" @click="open"><Icon name="search" class="h-[18px] w-[18px]" /></button>
    <dialog ref="dialog" class="ui-dialog ui-dialog-large text-left" :aria-label="t('Search')" @click="(event) => event.target === dialog && close()">
        <div class="flex items-center gap-3 border-b border-line px-4 py-3">
            <Icon name="search" class="h-5 w-5 text-muted" />
            <input
                ref="input"
                v-model="term"
                type="search"
                class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base text-ink outline-none focus:ring-0"
                :placeholder="t('Search projects, domains and people, or jump to a page')"
                role="combobox"
                aria-controls="command-results"
                :aria-expanded="entries.length > 0"
                :aria-activedescendant="entries.length ? `command-${active}` : undefined"
                @keydown="keys"
            >
            <kbd class="hidden rounded border border-line px-1.5 text-xs text-muted sm:inline">Esc</kbd>
        </div>
        <ul id="command-results" class="max-h-[60vh] overflow-y-auto p-2" role="listbox" :aria-label="t('Results')">
            <li v-if="entries.length === 0" class="px-3 py-6 text-center text-sm text-muted">{{ t('Nothing found.') }}</li>
            <li
                v-for="(entry, index) in entries"
                :id="`command-${index}`"
                :key="`${entry.group}-${entry.url}`"
                role="option"
                :aria-selected="index === active"
                :class="['flex cursor-pointer items-center justify-between gap-3 rounded-control px-3 py-2 text-sm', index === active ? 'bg-primary-soft text-ink' : 'text-ink hover:bg-surface-muted']"
                @mousemove="active = index"
                @click="go(entry)"
            >
                <span class="min-w-0">
                    <span class="block truncate font-semibold">{{ entry.title }}</span>
                    <span v-if="entry.subtitle" class="block truncate text-xs text-muted">{{ entry.subtitle }}</span>
                </span>
                <span class="shrink-0 text-xs text-subtle">{{ entry.group }}</span>
            </li>
        </ul>
    </dialog>
</template>
