<script setup lang="ts">
// Rounded-square initials for organisations (companies, agencies).
const props = withDefaults(defineProps<{ text: string; tone?: 'violet' | 'teal' | 'sky' | 'gray'; size?: 'sm' | 'md' | 'lg' }>(), { tone: 'gray', size: 'md' })
const tones = {
  violet: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  teal: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
  sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  gray: 'bg-black/[.05] text-ink dark:bg-white/10',
}
const sizes = { sm: 'size-8 text-xs rounded-lg', md: 'size-10 text-sm rounded-lg', lg: 'size-14 text-lg rounded-xl' }
const label = computed(() => (props.text.length <= 4 ? props.text.slice(0, 3) : initials(props.text)))
</script>

<template>
  <span aria-hidden="true" class="grid shrink-0 place-items-center font-semibold" :class="[tones[tone], sizes[size]]">{{ label }}</span>
</template>
