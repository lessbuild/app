<script setup lang="ts">
const props = withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger'; title?: string; dismissible?: boolean }>(), { tone: 'info' })
const visible = ref(true)
const styles = {
  info: ['info', 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100'],
  success: ['checkCircle', 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100'],
  warning: ['alert', 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100'],
  danger: ['alert', 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100'],
} as const
const s = computed(() => styles[props.tone])
const { t } = useT()
</script>

<template>
  <div v-if="visible" :role="tone === 'danger' ? 'alert' : 'status'" class="flex gap-3 rounded-xl border p-4 text-sm" :class="s[1]">
    <AcmeIcon :name="s[0]" :size="18" class="mt-px" />
    <div class="flex-1"><p v-if="title" class="font-semibold">{{ title }}</p><div :class="title && 'mt-0.5 opacity-90'"><slot /></div></div>
    <button v-if="dismissible" type="button" class="opacity-60 hover:opacity-100" :aria-label="t('Dismiss')" @click="visible = false"><AcmeIcon name="close" :size="16" /></button>
  </div>
</template>
