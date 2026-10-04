<script setup lang="ts">
// Deterministic gradient artwork for products, albums and listings (no image assets needed).
const props = withDefaults(defineProps<{ seed: string; icon?: string; label?: string; aspect?: string; rounded?: string }>(), { aspect: 'aspect-square', rounded: 'rounded-xl' })
const palettes = [
  ['#fda4af', '#f97316'], ['#93c5fd', '#6366f1'], ['#86efac', '#0d9488'], ['#fcd34d', '#f43f5e'], ['#c4b5fd', '#db2777'],
  ['#67e8f9', '#2563eb'], ['#fde68a', '#65a30d'], ['#f0abfc', '#7c3aed'], ['#a5b4fc', '#0f172a'], ['#fecaca', '#991b1b'],
]
const hash = computed(() => [...props.seed].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7))
const style = computed(() => {
  const [a, b] = palettes[hash.value % palettes.length]!
  const angle = hash.value % 360
  return { background: `radial-gradient(circle at ${20 + (hash.value % 60)}% ${20 + ((hash.value >> 3) % 60)}%, rgba(255,255,255,.45), transparent 45%), linear-gradient(${angle}deg, ${a}, ${b})` }
})
</script>

<template>
  <div class="relative grid shrink-0 place-items-center overflow-hidden text-white/90" :class="[aspect, rounded]" :style role="img" :aria-label="label ?? seed">
    <AcmeIcon v-if="icon" :name="icon" :size="28" class="drop-shadow" />
    <slot />
  </div>
</template>
