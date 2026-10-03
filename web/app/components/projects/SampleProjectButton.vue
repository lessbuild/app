<script setup lang="ts">
/** Create the sample project (made-up data, nothing reaching the outside world) and open it. */
const { t } = useT();
const busy = ref(false);

async function create() {
    busy.value = true;
    try {
        const result = await send<{ redirect: string; message: string }>('POST', '/projects/sample');
        flash(result.message, 'info');
        await navigateTo(local(result.redirect));
    } catch {
        busy.value = false;
    }
}
</script>

<template>
    <button type="button" class="ui-btn ui-btn-secondary" :disabled="busy" :aria-busy="busy || undefined" @click="create">{{ busy ? t('Working…') : t('Explore a sample project') }}</button>
</template>
