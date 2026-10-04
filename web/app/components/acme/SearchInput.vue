<script setup lang="ts">
const value = defineModel<string>({ default: '' })
const props = defineProps<{ label?: string; placeholder?: string }>()
const { t } = useT()
const name = computed(() => props.label ?? t('Search'))
</script>

<template>
  <div class="relative">
    <AcmeField v-model="value" :label="name" hide-label icon="search" :placeholder="placeholder ?? `${name}…`" type="search" />
    <button v-if="value" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted hover:text-ink" :aria-label="t('Clear search')" @click="value = ''">
      <AcmeIcon name="close" :size="14" />
    </button>
  </div>
</template>
