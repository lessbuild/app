import type { Ref } from 'vue';

/**
 * Data a dialog loads for itself the first time it opens (`?dialog=<id>`), so it can open over any page. Pass what the
 * page already has as `initial` and nothing is loaded.
 *
 * @param id The dialog's id.
 * @param path The API path to load, below /api/app.
 * @param initial What the page already has, if anything.
 */
export function useDialogData<T>(id: string, path: () => string, initial: () => T | undefined = () => undefined): { data: Ref<T | null>; failed: Ref<boolean> } {
    const route = useRoute();
    const data = ref(initial() ?? null) as Ref<T | null>;
    const failed = ref(false);

    watch(initial, (value) => {
        if (value !== undefined) {
            data.value = value;
        }
    });
    watch(() => route.query.dialog, async (dialog) => {
        if (import.meta.client && dialog === id && data.value === null) {
            failed.value = false;
            data.value = await send<T>('GET', path()).catch(() => {
                failed.value = true;
                return null;
            });
        }
    }, { immediate: true });

    return { data, failed };
}
