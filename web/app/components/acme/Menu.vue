<script setup lang="ts">
// Dropdown menu. Teleported + fixed-positioned so it never gets clipped by scroll containers.
export interface MenuItem { label?: string; icon?: string; danger?: boolean; checked?: boolean; to?: string; onSelect?: () => void; divider?: boolean }

const props = withDefaults(defineProps<{ items: MenuItem[]; label?: string; icon?: string; align?: 'left' | 'right'; variant?: 'primary' | 'secondary' | 'ghost'; size?: 'sm' | 'md' }>(), { align: 'left', variant: 'secondary', size: 'md' })
const open = ref(false)
const root = ref<HTMLElement>()
const panel = ref<HTMLElement>()
const pos = ref<Record<string, string>>({})
const id = useId()

const menuItems = () => [...(panel.value?.querySelectorAll<HTMLElement>('[role^="menuitem"]') ?? [])]
const trigger = () => root.value?.querySelector<HTMLElement>('[aria-haspopup]')

async function show() {
  const r = trigger()!.getBoundingClientRect()
  const height = props.items.length * 36 + 12
  const up = r.bottom + height > innerHeight - 8 && r.top > height
  pos.value = {
    ...(up ? { bottom: `${innerHeight - r.top + 4}px` } : { top: `${r.bottom + 4}px` }),
    ...(props.align === 'right' ? { right: `${innerWidth - r.right}px` } : { left: `${r.left}px` }),
  }
  open.value = true
  await nextTick()
  // Keep the panel inside the viewport horizontally (e.g. left-aligned menus near the right edge).
  const p = panel.value?.getBoundingClientRect()
  if (p && (p.right > innerWidth - 8 || p.left < 8)) {
    const left = Math.max(8, Math.min(p.left, innerWidth - p.width - 8))
    pos.value = { ...pos.value, left: `${left}px`, right: 'auto' }
  }
  menuItems()[0]?.focus()
}
function hide(refocus = true) {
  open.value = false
  if (refocus) trigger()?.focus()
}
const toggle = () => (open.value ? hide() : show())

function onKey(e: KeyboardEvent) {
  const list = menuItems()
  const i = list.indexOf(document.activeElement as HTMLElement)
  const go = (n: number) => { e.preventDefault(); list[(n + list.length) % list.length]?.focus() }
  if (e.key === 'ArrowDown') go(i + 1)
  else if (e.key === 'ArrowUp') go(i - 1)
  else if (e.key === 'Home') go(0)
  else if (e.key === 'End') go(list.length - 1)
  else if (e.key === 'Escape') { e.preventDefault(); hide() }
  else if (e.key === 'Tab') hide(false)
}
function select(item: MenuItem) {
  item.onSelect?.()
  if (item.to) navigateTo(item.to)
  if (item.checked === undefined) hide()
}
function onOutside(e: Event) {
  if (!root.value?.contains(e.target as Node) && !panel.value?.contains(e.target as Node)) hide(false)
}
const onScroll = (e: Event) => !panel.value?.contains(e.target as Node) && hide(false)
watch(open, (v) => {
  const m = v ? 'addEventListener' : 'removeEventListener'
  document[m]('pointerdown', onOutside)
  window[m]('scroll', onScroll, true)
  window[m]('resize', () => hide(false))
})
onBeforeUnmount(() => { document.removeEventListener('pointerdown', onOutside); window.removeEventListener('scroll', onScroll, true) })
const attrs = computed(() => ({ 'aria-haspopup': 'menu' as const, 'aria-expanded': open.value, 'aria-controls': open.value ? id : undefined, onClick: toggle }))
</script>

<template>
  <div ref="root" class="relative inline-flex">
    <slot name="trigger" :attrs :open>
      <AcmeBtn v-bind="attrs" :variant :size :icon :label="$slots.default ? undefined : label" :icon-right="$slots.default ? 'chevronDown' : undefined"><slot /></AcmeBtn>
    </slot>
    <Teleport to="#teleports">
      <div v-if="open" :id ref="panel" role="menu" :aria-label="label" class="fixed z-[60] min-w-48 animate-pop rounded-xl border border-line bg-surface p-1 text-sm shadow-lg" :style="pos" @keydown="onKey">
        <template v-for="(item, i) in items" :key="i">
          <div v-if="item.divider" role="separator" class="my-1 h-px bg-line" />
          <button
            v-else type="button" tabindex="-1" :role="item.checked === undefined ? 'menuitem' : 'menuitemcheckbox'" :aria-checked="item.checked"
            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left outline-none hover:bg-black/5 focus:bg-black/5 dark:hover:bg-white/10 dark:focus:bg-white/10"
            :class="item.danger && 'text-rose-600 dark:text-rose-400'" @click="select(item)"
          >
            <span v-if="item.checked !== undefined" class="grid size-4 place-items-center rounded border" :class="item.checked ? 'border-accent bg-accent text-accent-fg' : 'border-line'"><AcmeIcon v-if="item.checked" name="check" :size="12" /></span>
            <AcmeIcon v-else-if="item.icon" :name="item.icon" :size="16" class="opacity-70" />
            {{ item.label }}
          </button>
        </template>
      </div>
    </Teleport>
  </div>
</template>
