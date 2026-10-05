<script setup lang="ts">
// Code sample with optional language tabs and a copy button.
const props = withDefaults(defineProps<{ tabs?: { label: string; code: string }[]; code?: string; title?: string; dark?: boolean }>(), { dark: true })
const { t } = useT()
const items = computed(() => props.tabs ?? [{ label: props.title ?? t('Code'), code: props.code ?? '' }])
const active = ref(0)
const copied = ref(false)
async function copy() {
  // Only say "Copied" when the browser let us copy.
  const done = await navigator.clipboard.writeText(items.value[active.value]!.code).then(() => true, () => false)
  if (!done) return
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}
</script>

<template>
  <div class="overflow-hidden rounded-xl border" :class="dark ? 'border-zinc-800 bg-zinc-950 text-zinc-100' : 'border-line bg-surface'">
    <div class="flex items-center gap-1 border-b px-2" :class="dark ? 'border-zinc-800' : 'border-line'">
      <div class="flex flex-1 overflow-x-auto overflow-y-hidden overscroll-x-contain [scrollbar-width:none]" role="tablist" :aria-label="t('Language')">
        <button v-for="(tab, i) in items" :key="tab.label" type="button" role="tab" :aria-selected="active === i" class="shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-xs font-medium" :class="active === i ? (dark ? 'border-white text-white' : 'border-accent text-ink') : 'border-transparent opacity-60 hover:opacity-100'" @click="active = i">{{ tab.label }}</button>
      </div>
      <button type="button" class="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs opacity-70 hover:opacity-100" :aria-label="copied ? t('Copied') : t('Copy code')" @click="copy"><AcmeIcon :name="copied ? 'check' : 'copy'" :size="14" />{{ copied ? t('Copied') : t('Copy') }}</button>
    </div>
    <pre class="overflow-x-auto p-4 text-[0.8125rem] leading-relaxed"><code class="font-mono">{{ items[active]!.code }}</code></pre>
  </div>
</template>
