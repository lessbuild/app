<script setup lang="ts">
/**
 * The "Confirm it's you" dialog the app opens when an action needs a recent confirmation (the API's 423). Mounted once
 * in the shell; registers itself as the confirmation handler.
 */
const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();
const open = ref(false);
const returnTo = ref<string | undefined>(undefined);
let pending: ((confirmed: boolean) => void) | null = null;

/** Close the dialog and tell the waiting request whether to go ahead. */
function finish(confirmed: boolean) {
    pending?.(confirmed);
    pending = null;
    open.value = false;
    dialog.value?.close();
}

onMounted(() => {
    setConfirmHandler(() => new Promise<boolean>((resolve) => {
        pending = resolve;
        returnTo.value = window.location.pathname + window.location.search;
        open.value = true;
        dialog.value?.showModal();
    }));
});
onBeforeUnmount(() => setConfirmHandler(null));
</script>

<template>
    <dialog ref="dialog" class="ui-dialog text-left" :aria-labelledby="titleId" @cancel.prevent="finish(false)">
        <div v-if="open" data-modal-panel>
            <header class="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
                <div class="min-w-0">
                    <h2 :id="titleId" class="text-lg font-extrabold text-ink">{{ t('Confirm it’s you') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ t('This is a sensitive action. Confirm your identity to continue; you won’t be asked again for a while.') }}</p>
                </div>
                <button type="button" class="ui-icon-btn" :aria-label="t('Cancel')" @click="finish(false)">
                    <Icon name="close" class="h-5 w-5" />
                </button>
            </header>
            <div class="px-5 py-5 sm:px-6">
                <ConfirmIdentityOptions :return-to="returnTo" @confirmed="finish(true)" />
            </div>
        </div>
    </dialog>
</template>
