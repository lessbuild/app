<script setup lang="ts">
import type { Inbox } from '~/types/notifications';

/** The person's notifications across their accounts: all or unread, by kind or words, and exported as CSV. */
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
    { key: '', label: t('All') },
    { key: 'unread', label: data.value.unreadCount > 0 ? `${t('Unread')} (${data.value.unreadCount})` : t('Unread') },
]);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Notifications')" :description="data.unreadCount > 0 ? tc(':count unread|:count unread', data.unreadCount) : t('You’re all caught up.')">
            <template #actions>
                <a :href="exportUrl" class="ui-btn ui-btn-quiet" download>{{ t('Export CSV') }}</a>
                <UiButton v-if="data.unreadCount > 0" @click="markAllRead">{{ t('Mark all as read') }}</UiButton>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <nav class="ui-local-nav" :aria-label="t('Filter notifications')">
                <div class="ui-local-nav__scroll">
                    <NuxtLink v-for="tab in tabs" :key="tab.key" :to="{ query: { ...route.query, filter: tab.key || undefined, cursor: undefined } }" class="ui-local-nav__link" :aria-current="(route.query.filter ?? '') === tab.key ? 'page' : undefined">{{ tab.label }}</NuxtLink>
                </div>
            </nav>
            <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="apply">
                <SelectField v-model="type" name="type" :label="t('Kind')" :placeholder="t('Everything')" :options="typeOptions" />
                <InputField v-model="search" name="q" type="search" :label="t('Search')" maxlength="100" />
                <UiButton type="submit">{{ t('Show') }}</UiButton>
            </form>
        </div>

        <div class="ui-card overflow-hidden"><NotificationList :items="data.items" /></div>

        <nav v-if="data.nextCursor || data.previousCursor" class="flex justify-between gap-3" :aria-label="t('Notification pages')">
            <UiButton :to="{ query: { ...route.query, cursor: data.previousCursor ?? undefined } }" :class="!data.previousCursor && 'pointer-events-none opacity-50'" :aria-disabled="!data.previousCursor || undefined">{{ t('Newer') }}</UiButton>
            <UiButton :to="{ query: { ...route.query, cursor: data.nextCursor ?? undefined } }" :class="!data.nextCursor && 'pointer-events-none opacity-50'" :aria-disabled="!data.nextCursor || undefined">{{ t('Older') }}</UiButton>
        </nav>
    </div>
</template>
