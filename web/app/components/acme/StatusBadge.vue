<script setup lang="ts">
// Badge whose colour comes from the shared status map (utils/status.ts).
defineProps<{ status: string; dot?: boolean }>()
</script>

<template>
  <AcmeBadge :tone="statusTone(status)" :dot><slot>{{ status }}</slot></AcmeBadge>
</template>
