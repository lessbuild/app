<script setup lang="ts">
import type { InboxItem } from '~/types/notifications';

/** Notifications, newest first; opening one marks it read and goes where it leads. */
defineProps<{ items: InboxItem[]; compact?: boolean }>();
const emit = defineEmits<{ opened: [] }>();
const { t } = useT();

async function open(item: InboxItem) {
    const result = await send<{ redirect: string }>('POST', `/notifications/${item.id}/open`).catch(() => null);
    emit('opened');
    if (result) {
        await refreshShell();
        await navigateTo(local(result.redirect));
    }
}
</script>

<template>
    <p v-if="items.length === 0" :class="['text-sm text-muted', compact ? 'px-3 py-4' : 'py-6 text-center']">{{ t('You’re all caught up.') }}</p>
    <ul v-else class="divide-y divide-line">
        <li v-for="item in items" :key="item.id">
            <button type="button" :class="['flex w-full items-start gap-3 text-left hover:bg-surface-muted focus-visible:bg-surface-muted focus-visible:outline-none', compact ? 'px-3 py-2.5' : 'px-5 py-4']" @click="open(item)">
                <span :class="['mt-1.5 size-2 shrink-0 rounded-full', item.read ? 'bg-transparent' : 'bg-primary']" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span :class="['block text-sm', item.read ? 'font-semibold text-muted' : 'font-extrabold text-ink']">{{ item.title }}<span v-if="!item.read" class="sr-only"> ({{ t('unread') }})</span></span>
                    <span v-if="item.body" :class="['mt-0.5 block text-sm text-muted', compact && 'line-clamp-2']">{{ item.body }}</span>
                    <RelativeTime class="mt-1 block text-xs text-subtle" :at="item.at" />
                </span>
            </button>
        </li>
    </ul>
</template>
