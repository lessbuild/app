<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';

/** A form's Cancel: back to `to` on a page of its own, or just closing the drawer when the form opened in one. */
defineProps<{ to: RouteLocationRaw }>();
const { t } = useT();
const panel = usePagePanel();
</script>

<template>
    <AcmeBtn v-if="panel" @click="panel.close()">{{ t('Cancel') }}</AcmeBtn>
    <AcmeBtn v-else :to="to">{{ t('Cancel') }}</AcmeBtn>
</template>
