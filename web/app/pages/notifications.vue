<script setup lang="ts">
import type { Inbox, InboxItem } from '~/types/notifications';

/**
 * The person's notifications across their accounts (the Acme theme's activity page): all or unread, grouped by day, by
 * kind or words, and exported as CSV.
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
            <section v-for="group in days" :key="group.label">
                <AcmeSectionTitle :title="group.label" />
                <AcmeListGroup bordered :label="group.label">
                    <li v-for="item in group.items" :key="item.id">
                        <button type="button" class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-black/[.02] dark:hover:bg-white/[.03]" @click="open(item)">
                            <AcmeIconBubble icon="bell" />
                            <span class="min-w-0 flex-1 text-sm">
                                <span :class="['font-medium', item.read ? 'text-muted' : 'text-ink']">{{ item.title }}</span>
                                <span v-if="item.body" class="block text-muted">{{ item.body }}</span>
                                <RelativeTime class="block text-muted" :at="item.at" />
                            </span>
                            <span v-if="!item.read" class="mt-2 size-2 shrink-0 rounded-full bg-blue-500"><span class="sr-only">{{ t('unread') }}</span></span>
                        </button>
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
