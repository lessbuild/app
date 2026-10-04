<script setup lang="ts">
defineProps<{ items: { label: string; to?: string }[] }>()
const { t } = useT()
</script>

<template>
  <nav :aria-label="t('Breadcrumb')">
    <ol class="flex flex-wrap items-center gap-1.5 text-sm text-muted">
      <li v-for="(item, i) in items" :key="i" class="flex items-center gap-1.5">
        <NuxtLink v-if="item.to && i < items.length - 1" :to="item.to" class="hover:text-ink">{{ item.label }}</NuxtLink>
        <span v-else :aria-current="i === items.length - 1 ? 'page' : undefined" class="text-ink">{{ item.label }}</span>
        <AcmeIcon v-if="i < items.length - 1" name="chevronRight" :size="14" />
      </li>
    </ol>
  </nav>
</template>
