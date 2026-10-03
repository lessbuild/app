<script setup lang="ts">
/**
 * A dialog on the page, opened by its trigger (the `trigger` slot gets `open`) or by `?dialog=<id>` in the address,
 * so it can be linked to. Opening it adds `?dialog=<id>`; closing removes it, so Back closes it too. The default slot
 * gets `close` and is only rendered while the dialog is open.
 */
const props = withDefaults(defineProps<{ id: string; title: string; description?: string; size?: 'default' | 'wide' | 'large'; bodyClass?: string }>(), {
    description: undefined, size: 'default', bodyClass: 'px-5 py-5 sm:px-6',
});
const { t } = useT();
const route = useRoute();
const router = useRouter();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();
const isOpen = computed(() => route.query.dialog === props.id);

/** The address with this dialog open, or with no dialog. */
function query(open: boolean) {
    const next = { ...route.query };
    if (open) {
        next.dialog = props.id;
    } else {
        delete next.dialog;
    }
    return { query: next, hash: route.hash };
}

const open = () => router.push(query(true));
const close = () => router.replace(query(false));

watch(isOpen, (value) => {
    if (value && dialog.value && !dialog.value.open) {
        dialog.value.showModal();
    }
    if (!value && dialog.value?.open) {
        dialog.value.close();
    }
}, { flush: 'post' });
onMounted(() => {
    if (isOpen.value) {
        dialog.value?.showModal();
    }
});
</script>

<template>
    <slot name="trigger" :open="open" />
    <dialog
        :id="id"
        ref="dialog"
        :class="['ui-dialog text-left', size === 'wide' && 'ui-dialog-wide', size === 'large' && 'ui-dialog-large']"
        :aria-labelledby="titleId"
        @cancel.prevent="close"
        @click="(event) => event.target === dialog && close()"
    >
        <div v-if="isOpen" data-modal-panel>
            <header data-modal-header class="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
                <div class="min-w-0">
                    <h2 :id="titleId" class="text-lg font-extrabold text-ink">{{ title }}</h2>
                    <p v-if="description" class="mt-1 text-sm text-muted">{{ description }}</p>
                </div>
                <button type="button" class="ui-icon-btn" :aria-label="t('Close :title', { title })" autofocus @click="close">
                    <Icon name="close" class="h-5 w-5" />
                </button>
            </header>
            <div data-modal-body :class="bodyClass"><slot :close="close" /></div>
        </div>
    </dialog>
</template>
