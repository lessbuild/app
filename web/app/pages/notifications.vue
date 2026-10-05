<script setup lang="ts">
import type { Inbox, InboxItem } from '~/types/notifications';

/**
 * The person's notifications across their accounts (the Acme theme's activity page): all or unread, grouped by day, by
 * kind or words, and exported as CSV. Each can be marked read or unread or deleted, several at once when ticked, and
 * everything read can be cleared.
 */
definePageMeta({ layout: 'app' });
const { t, tc } = useT();
const route = useRoute();
const keys = ['filter', 'type', 'q', 'cursor'] as const;
const { data } = await useApi<Inbox>('/notifications', () => Object.fromEntries(keys.map((key) => [key, typeof route.query[key] === 'string' ? route.query[key] : undefined])));
const search = ref(data.value.filters.q ?? '');
const type = ref(data.value.filters.type ?? '');
const typeOptions = computed(() => Object.entries(data.value.types).map(([value, label]) => ({ value, label })));
const exportUrl = computed(() => `/api/app/notifications/export?${new URLSearchParams(Object.entries({ filter: data.value.filters.unread ? 'unread' : '', type: data.value.filters.type ?? '', q: data.value.filters.q ?? '' }).filter(([, value]) => value)).toString()}`);

/** Show the chosen filters, from the newest notification. */
function apply() {
    navigateTo({ query: { filter: route.query.filter, type: type.value || undefined, q: search.value || undefined } });
}

const selected = ref<string[]>([]);
const everyId = computed(() => data.value.items.map((item) => item.id));
const allSelected = computed(() => everyId.value.length > 0 && everyId.value.every((id) => selected.value.includes(id)));
watch(everyId, (ids) => (selected.value = selected.value.filter((id) => ids.includes(id))));

/**
 * Mark notifications read or unread, or delete them, then load the list again.
 *
 * @param action What to do.
 * @param ids Which notifications.
 */
async function change(action: 'read' | 'unread' | 'delete', ids: string[]) {
    if (ids.length === 0) {
        return;
    }
    const result = await send<{ message: string }>('POST', '/notifications/bulk', { action, ids }).catch(() => null);
    if (result) {
        flash(result.message);
        selected.value = [];
        await Promise.all([refreshPage(), refreshShell()]);
    }
}

/**
 * Tick or untick one notification.
 *
 * @param id The notification.
 */
function toggleOne(id: string) {
    selected.value = selected.value.includes(id) ? selected.value.filter((other) => other !== id) : [...selected.value, id];
}

/**
 * A notification's own actions: read or unread, and delete.
 *
 * @param item The notification.
 */
const actionsFor = (item: InboxItem) => [
    item.read ? { label: t('Mark as unread'), icon: 'circle', onSelect: () => change('unread', [item.id]) } : { label: t('Mark as read'), icon: 'check', onSelect: () => change('read', [item.id]) },
    { label: t('Delete'), icon: 'trash', danger: true, onSelect: () => change('delete', [item.id]) },
];

/** Mark every notification read. */
async function markAllRead() {
    const result = await send<{ message: string }>('POST', '/notifications/read').catch(() => null);
    if (result) {
        flash(result.message);
        await refreshPage();
    }
}

const tabs = computed(() => [
    { value: 'all', label: t('All') },
    { value: 'unread', label: t('Unread'), count: data.value.unreadCount > 0 ? data.value.unreadCount : undefined },
]);
const filter = computed({
    get: () => (route.query.filter === 'unread' ? 'unread' : 'all'),
    set: (value: string) => navigateTo({ query: { ...route.query, filter: value === 'unread' ? 'unread' : undefined, cursor: undefined } }),
});
/** The notifications under Today, Yesterday and Earlier, leaving out empty days. */
const days = computed(() => {
    const start = new Date();
    start.setHours(0, 0, 0, 0);
    const today = start.getTime();
    const yesterday = today - 864e5;
    const day = (at: string) => (new Date(at).getTime() >= today ? t('Today') : new Date(at).getTime() >= yesterday ? t('Yesterday') : t('Earlier'));
    return [t('Today'), t('Yesterday'), t('Earlier')]
        .map((label) => ({ label, items: data.value.items.filter((item) => day(item.at) === label) }))
        .filter((group) => group.items.length > 0);
});

/**
 * Mark a notification read and go where it leads.
 *
 * @param item The notification.
 */
async function open(item: InboxItem) {
    const result = await send<{ redirect: string }>('POST', `/notifications/${item.id}/open`).catch(() => null);
    if (result) {
        await refreshShell();
        await navigateTo(local(result.redirect));
    }
}
</script>

<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-ink sm:text-[1.75rem]">{{ t('Notifications') }}</h1>
                <p class="mt-1 text-muted">{{ data.unreadCount > 0 ? tc(':count unread|:count unread', data.unreadCount) : t('You’re all caught up.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <AcmeBtn icon="check" :disabled="data.unreadCount === 0" @click="markAllRead">{{ t('Mark all as read') }}</AcmeBtn>
                <DeleteDialog id="clear-read" :title="t('Delete read notifications?')" :description="t('Everything you’ve read is deleted. Unread notifications stay.')" action="/api/app/notifications/read" :submit-label="t('Delete read')">
                    <template #trigger="{ open: show }"><AcmeBtn icon="trash" @click="show">{{ t('Clear read') }}</AcmeBtn></template>
                </DeleteDialog>
                <AcmeBtn icon="download" :to="exportUrl" external>{{ t('Export CSV') }}</AcmeBtn>
                <AcmeBtn icon="config" to="/settings/notifications">{{ t('Preferences') }}</AcmeBtn>
            </div>
        </header>
        <div class="max-w-3xl space-y-6">
            <AcmeTabs v-model="filter" :tabs="tabs" :label="t('Filter notifications')" />
            <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="apply">
                <SelectField v-model="type" name="type" :label="t('Kind')" :placeholder="t('Everything')" :options="typeOptions" />
                <InputField v-model="search" name="q" type="search" :label="t('Search')" maxlength="100" />
                <AcmeBtn type="submit">{{ t('Show') }}</AcmeBtn>
            </form>
            <SavedViews page="notifications" :keys="['filter', 'type', 'q']" />
            <div v-if="data.items.length > 0" class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface px-4 py-2.5 text-sm" role="toolbar" :aria-label="t('Selected notifications')">
                <label class="flex items-center gap-2 text-muted"><input type="checkbox" class="size-4 accent-[var(--ui-primary)]" :checked="allSelected" @change="selected = allSelected ? [] : [...everyId]">{{ selected.length > 0 ? tc(':count selected|:count selected', selected.length) : t('Select all') }}</label>
                <template v-if="selected.length > 0">
                    <AcmeBtn size="sm" icon="check" @click="change('read', selected)">{{ t('Mark as read') }}</AcmeBtn>
                    <AcmeBtn size="sm" icon="circle" @click="change('unread', selected)">{{ t('Mark as unread') }}</AcmeBtn>
                    <AcmeBtn size="sm" icon="trash" variant="danger" @click="change('delete', selected)">{{ t('Delete') }}</AcmeBtn>
                </template>
            </div>
            <section v-for="group in days" :key="group.label">
                <AcmeSectionTitle :title="group.label" />
                <AcmeListGroup bordered :label="group.label">
                    <li v-for="item in group.items" :key="item.id" class="flex items-start gap-1 pl-4 pr-2">
                        <input type="checkbox" class="mt-5 size-4 shrink-0 accent-[var(--ui-primary)]" :checked="selected.includes(item.id)" :aria-label="t('Select :title', { title: item.title })" @change="toggleOne(item.id)">
                        <button type="button" class="flex min-w-0 flex-1 items-start gap-3 px-2 py-3.5 text-left transition-colors hover:bg-black/[.02] dark:hover:bg-white/[.03]" @click="open(item)">
                            <AcmeIconBubble icon="bell" />
                            <span class="min-w-0 flex-1 text-sm">
                                <span :class="['font-medium', item.read ? 'text-muted' : 'text-ink']">{{ item.title }}</span>
                                <span v-if="item.body" class="block text-muted">{{ item.body }}</span>
                                <RelativeTime class="block text-muted" :at="item.at" />
                            </span>
                            <span v-if="!item.read" class="mt-2 size-2 shrink-0 rounded-full bg-blue-500"><span class="sr-only">{{ t('unread') }}</span></span>
                        </button>
                        <AcmeMenu class="mt-2.5" :items="actionsFor(item)" :label="t('Actions for :title', { title: item.title })" icon="dots" variant="ghost" size="sm" align="right" />
                    </li>
                </AcmeListGroup>
            </section>
            <AcmeEmptyCard v-if="days.length === 0" icon="bell" :title="t('You’re all caught up.')" :description="t('New notifications show up here.')" />
            <nav v-if="data.nextCursor || data.previousCursor" class="flex justify-between gap-3" :aria-label="t('Notification pages')">
                <AcmeBtn :to="{ query: { ...route.query, cursor: data.previousCursor ?? undefined } }" :disabled="!data.previousCursor">{{ t('Newer') }}</AcmeBtn>
                <AcmeBtn :to="{ query: { ...route.query, cursor: data.nextCursor ?? undefined } }" :disabled="!data.nextCursor">{{ t('Older') }}</AcmeBtn>
            </nav>
        </div>
    </div>
</template>
