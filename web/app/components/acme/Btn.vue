<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { NuxtLink } from '#components'

const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md'
  icon?: string
  iconRight?: string
  to?: RouteLocationRaw
  loading?: boolean
  disabled?: boolean
  type?: 'button' | 'submit'
  /** Accessible name; required for icon-only buttons. */
  label?: string
}>(), { variant: 'secondary', size: 'md', type: 'button' })

const slots = useSlots()
const iconOnly = computed(() => !slots.default)
const variants = {
  primary: 'border-accent bg-accent text-accent-fg hover:opacity-90 [box-shadow:inset_0_1px_0_rgb(255_255_255/0.12),0_1px_2px_rgb(0_0_0/0.12)]',
  secondary: 'border-line bg-surface shadow-card hover:bg-black/[.02] dark:hover:bg-white/[.06]',
  ghost: 'border-transparent hover:bg-black/[.05] dark:hover:bg-white/[.08]',
  danger: 'border-rose-600 bg-rose-600 text-white hover:bg-rose-700',
}
const cls = computed(() => [
  'inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border font-medium transition disabled:pointer-events-none disabled:opacity-50',
  variants[props.variant],
  props.to && (props.disabled || props.loading) && 'pointer-events-none opacity-50',
  props.size === 'sm' ? (iconOnly.value ? 'size-8' : 'h-8 px-2.5 text-[0.8125rem]') : (iconOnly.value ? 'size-9' : 'h-9 px-3.5 text-sm'),
])
</script>

<template>
  <component
    :is="to ? NuxtLink : 'button'" :to :type="to ? undefined : type" :class="cls"
    :disabled="!to && (disabled || loading) ? true : undefined" :aria-disabled="to && (disabled || loading) ? true : undefined" :aria-label="label" :aria-busy="loading || undefined" :title="iconOnly ? label : undefined"
  >
    <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
    <AcmeIcon v-else-if="icon" :name="icon" :size="size === 'sm' ? 15 : 16" />
    <slot />
    <AcmeIcon v-if="iconRight" :name="iconRight" :size="16" />
  </component>
</template>
