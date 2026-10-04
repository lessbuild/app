<script setup lang="ts">
const page = defineModel<number>({ required: true })
const props = defineProps<{ total: number; pageSize: number }>()
const pages = computed(() => Math.max(1, Math.ceil(props.total / props.pageSize)))
const from = computed(() => (props.total ? (page.value - 1) * props.pageSize + 1 : 0))
const to = computed(() => Math.min(props.total, page.value * props.pageSize))
const { t } = useT()
</script>

<template>
  <nav :aria-label="t('Pagination')" class="flex items-center justify-between gap-4 text-sm">
    <p class="text-muted">{{ t('Showing :from–:to of :total', { from, to, total }) }}</p>
    <div class="flex items-center gap-1">
      <AcmeBtn size="sm" variant="ghost" icon="chevronLeft" :label="t('Previous page')" :disabled="page <= 1" @click="page--" />
      <button v-for="p in pages" :key="p" type="button" class="size-8 rounded-lg text-sm tabular-nums" :class="p === page ? 'bg-accent text-accent-fg' : 'hover:bg-black/5 dark:hover:bg-white/10'" :aria-current="p === page ? 'page' : undefined" @click="page = p">{{ p }}</button>
      <AcmeBtn size="sm" variant="ghost" icon="chevronRight" :label="t('Next page')" :disabled="page >= pages" @click="page++" />
    </div>
  </nav>
</template>
