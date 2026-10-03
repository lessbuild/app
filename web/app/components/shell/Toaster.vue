<script setup lang="ts">
/**
 * Shows the message an action left (after a navigation, or on the same page after a save), announced to screen
 * readers, for a few seconds.
 */
const { t } = useT();
const route = useRoute();
const toast = ref<{ message: string; tone: string } | null>(null);
let timer: number | undefined;

/** Show the waiting message, if there is one. */
function present() {
    const next = takeFlash();
    if (next) {
        toast.value = next;
        window.clearTimeout(timer);
        timer = window.setTimeout(() => (toast.value = null), 6000);
    }
}

onMounted(() => {
    window.addEventListener(EVENT, present);
    // A message carried across a full page load is waiting when the page appears.
    present();
});
onBeforeUnmount(() => window.removeEventListener(EVENT, present));
// …and one carried across a navigation inside the app, once the new page is there.
watch(() => route.path, () => nextTick(present));
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center px-4" aria-live="polite" role="status">
        <div v-if="toast" :class="['ui-alert pointer-events-auto flex max-w-lg items-start gap-3 shadow-panel', `ui-alert--${toast.tone}`, `ui-alert-${toast.tone}`]">
            <span class="text-sm">{{ toast.message }}</span>
            <button type="button" class="ui-icon-btn ui-icon-btn-sm -my-1 -mr-1 shrink-0" :aria-label="t('Dismiss')" @click="toast = null">
                <Icon name="close" class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
