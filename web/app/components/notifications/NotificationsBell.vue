<script setup lang="ts">
import type { Inbox } from '~/types/notifications';

/** The bell in the topbar: the unread count, and a panel with the latest notifications that loads when it opens. */
const props = defineProps<{ unread: number }>();
const { t, tc } = useT();
const panel = ref<HTMLDetailsElement | null>(null);
const inbox = ref<Inbox | null>(null);
const loading = ref(false);

/** Load the latest notifications when the panel opens. */
async function toggled() {
    if (panel.value?.open) {
        loading.value = true;
        inbox.value = await send<Inbox>('GET', '/notifications?per=8').catch(() => null);
        loading.value = false;
    }
}

/** Close the panel (after opening a notification or following a link). */
function close() {
    if (panel.value) {
        panel.value.open = false;
    }
}

async function markAllRead() {
    await send('POST', '/notifications/read').catch(() => null);
    await Promise.all([refreshShell(), toggled()]);
}

const label = computed(() => (props.unread > 0 ? tc('Notifications, :count unread|Notifications, :count unread', props.unread) : t('Notifications')));
</script>

<template>
    <details ref="panel" class="relative" @toggle="toggled">
        <summary class="ui-icon-btn relative list-none" :aria-label="label">
            <Icon name="bell" class="h-[18px] w-[18px]" />
            <span v-if="unread > 0" class="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-extrabold leading-4 text-white" aria-hidden="true">
                {{ unread > 9 ? '9+' : unread }}
            </span>
        </summary>
        <div class="absolute right-0 top-full z-40 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-panel border border-line bg-surface shadow-panel">
            <div class="flex items-center justify-between gap-3 border-b border-line px-3 py-2.5">
                <p class="text-sm font-extrabold text-ink">{{ t('Notifications') }}</p>
                <button v-if="unread > 0" type="button" class="text-xs font-bold text-primary hover:underline" @click="markAllRead">{{ t('Mark all as read') }}</button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <p v-if="loading && !inbox" class="px-3 py-4 text-sm text-muted" role="status">{{ t('Loading…') }}</p>
                <NotificationList v-else-if="inbox" :items="inbox.items" compact @opened="close" />
            </div>
            <NuxtLink to="/notifications" class="block border-t border-line px-3 py-2.5 text-center text-sm font-bold text-primary hover:bg-surface-muted" @click="close">{{ t('See all notifications') }}</NuxtLink>
        </div>
    </details>
</template>
