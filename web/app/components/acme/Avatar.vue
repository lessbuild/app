<script setup lang="ts">
const props = withDefaults(defineProps<{ name: string; src?: string; size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' }>(), { size: 'md' })
const gradients = ['from-pink-400 to-rose-500', 'from-sky-400 to-blue-500', 'from-emerald-400 to-teal-500', 'from-amber-300 to-orange-500', 'from-violet-400 to-purple-500', 'from-lime-400 to-green-500']
const gradient = computed(() => gradients[[...props.name].reduce((h, c) => h + c.charCodeAt(0), 0) % gradients.length])
const sizes = { xs: 'size-6 text-[0.625rem]', sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-12 text-base', xl: 'size-16 text-xl' }
</script>

<template>
  <img v-if="src" :src :alt="name" class="shrink-0 rounded-full object-cover" :class="sizes[size]">
  <span v-else role="img" :aria-label="name" class="grid shrink-0 place-items-center rounded-full bg-gradient-to-br font-semibold text-white" :class="[sizes[size], gradient]">{{ initials(name) }}</span>
</template>
