<script setup lang="ts">
// Slide-over panel from the right. Same focus handling as Modal: focus moves in, Tab is trapped,
// Esc / backdrop close, focus returns to the opener.
const open = defineModel<boolean>({ default: false })
withDefaults(defineProps<{ title: string; description?: string; width?: string }>(), { width: 'max-w-lg' })
const panel = ref<HTMLElement>()
const id = useId()
let opener: HTMLElement | null = null
const focusables = () => [...(panel.value?.querySelectorAll<HTMLElement>('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])') ?? [])]
watch(open, async (v) => {
  if (v) {
    opener = document.activeElement as HTMLElement
    await nextTick()
    focusables()[0]?.focus()
  } else {
    opener?.focus()
  }
})
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') { open.value = false; return }
  if (e.key !== 'Tab') return
  const f = focusables()
  if (!f.length) return
  if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1)!.focus() }
  else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0]!.focus() }
}
const { t } = useT()
</script>

<template>
  <Teleport to="#teleports">
    <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150">
      <div v-if="open" class="fixed inset-0 z-50 bg-black/30" @mousedown.self="open = false">
        <div ref="panel" role="dialog" aria-modal="true" :aria-labelledby="`${id}-t`" class="absolute inset-y-0 right-0 flex w-full animate-[drawer-in_.22s_ease-out] flex-col border-l border-line bg-panel shadow-2xl" :class="width" @keydown="onKey">
          <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div class="min-w-0">
              <slot name="eyebrow" />
              <h2 :id="`${id}-t`" class="truncate text-lg font-semibold">{{ title }}</h2>
              <p v-if="description" class="mt-0.5 text-sm text-muted">{{ description }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-1"><slot name="actions" /><AcmeBtn variant="ghost" size="sm" icon="close" :label="t('Close panel')" @click="open = false" /></div>
          </header>
          <div class="flex-1 overflow-y-auto p-5"><slot /></div>
          <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-line px-5 py-3"><slot name="footer" /></footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
