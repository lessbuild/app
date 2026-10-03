<script setup lang="ts">
/** Switch between light and dark, saved where the theme boot script reads it. */
const { t } = useT();
const dark = ref(false);
let observer: MutationObserver | null = null;

onMounted(() => {
    // Follow the <html> class, which the boot script, this button and the system setting all change.
    const root = document.documentElement;
    dark.value = root.classList.contains('dark');
    observer = new MutationObserver(() => (dark.value = root.classList.contains('dark')));
    observer.observe(root, { attributes: true, attributeFilter: ['class'] });
});
onBeforeUnmount(() => observer?.disconnect());

/** Flip the theme and remember the choice. */
function toggle() {
    const root = document.documentElement;
    const next = !root.classList.contains('dark');
    root.classList.toggle('dark', next);
    root.classList.toggle('light', !next);
    root.dataset.appearance = next ? 'dark' : 'light';
    try {
        localStorage.setItem(`${root.dataset.storageNamespace ?? 'signal-starter'}-appearance`, next ? 'dark' : 'light');
    } catch {
        // Private browsing: the choice lasts for this page only.
    }
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', next ? '#17191c' : '#f4f7fb');
}
</script>

<template>
    <button type="button" class="ui-icon-btn" :aria-pressed="dark" :aria-label="dark ? t('Use light theme') : t('Use dark theme')" @click="toggle">
        <svg class="h-[19px] w-[19px] dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.6 8.6 0 1 0 20.2 15.1Z" /></svg>
        <svg class="hidden h-[19px] w-[19px] dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3.6" /><path stroke-linecap="round" d="M12 2.5v2M12 19.5v2M4.3 4.3l1.4 1.4m12.6 12.6 1.4 1.4M2.5 12h2m15 0h2M4.3 19.7l1.4-1.4M18.3 5.7l1.4-1.4" /></svg>
    </button>
</template>
