<script setup lang="ts">
/**
 * The header's "What's new" button and its dialog: the three latest changelog entries, links to the full changelog
 * and the roadmap, and "Got it", which clears the dot. A dot marks entries the person hasn't seen.
 */
defineProps<{ unseen: number }>();
const { t } = useT();
</script>

<template>
    <UiDialog id="whats-new" :title="t('What’s new')" :description="t('The latest improvements to :app.', { app: 'BuildPusher' })">
        <template #trigger="{ open }">
            <button type="button" class="ui-icon-btn relative" :aria-label="unseen > 0 ? t('What’s new (:count new)', { count: unseen }) : t('What’s new')" @click="open">
                <Icon name="sparkles" class="h-[18px] w-[18px]" />
                <span v-if="unseen > 0" class="absolute right-1 top-1 size-2 rounded-full bg-primary" aria-hidden="true" />
            </button>
        </template>
        <template #default="{ close }">
            <WhatsNewEntries @done="close" />
        </template>
    </UiDialog>
</template>
