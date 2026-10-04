<script setup lang="ts">
// Secondary navigation: a vertical, grouped list on large screens and a compact
// horizontally scrolling pill bar on small screens (no long stacked menu to scroll past).
export interface SubNavItem { label: string; to: string; icon?: string; children?: { label: string; to: string }[] }
const props = defineProps<{ groups: { title: string; items: SubNavItem[] }[]; label: string; isActive?: (to: string) => boolean }>()
const route = useRoute()
const active = (to: string) => (props.isActive ? props.isActive(to) : route.path === to)
const openGroups = reactive<Record<string, boolean>>({})
const expanded = reactive<Record<string, boolean>>({})
watch(() => route.path, p => (expanded[p] = true), { immediate: true })
const strip = ref<HTMLElement>()
// Keep the active pill in view on small screens.
watch(() => route.path, () => nextTick(() => strip.value?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({ inline: 'center', block: 'nearest' })))
onMounted(() => strip.value?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({ inline: 'center', block: 'nearest' }))
const { t } = useT()
</script>

<template>
  <nav :aria-label="label">
    <!-- Small screens: pill bar -->
    <div ref="strip" class="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:-mx-6 sm:px-6 lg:hidden">
      <template v-for="(g, gi) in groups" :key="g.title">
        <span v-if="gi > 0" class="my-1.5 w-px shrink-0 bg-line" aria-hidden="true" />
        <NuxtLink
          v-for="item in g.items" :key="item.to" :to="item.to" :aria-current="active(item.to) ? 'page' : undefined"
          class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm transition-colors"
          :class="active(item.to) ? 'border-accent bg-accent text-accent-fg' : 'border-line bg-surface text-muted hover:text-ink'"
        >
          <AcmeIcon v-if="item.icon" :name="item.icon" :size="15" />{{ item.label }}
        </NuxtLink>
      </template>
    </div>

    <!-- Large screens: grouped vertical list -->
    <div class="hidden lg:block">
      <div v-for="g in groups" :key="g.title" class="mb-3">
        <button type="button" class="flex w-full items-center justify-between rounded-md px-3 pb-1.5 pt-4" :aria-expanded="openGroups[g.title] !== false" @click="openGroups[g.title] = openGroups[g.title] === false">
          <span class="section-label">{{ g.title }}</span>
          <AcmeIcon name="chevronUp" :size="18" class="text-muted transition-transform" :class="openGroups[g.title] === false && 'rotate-180'" />
        </button>
        <AcmeCollapse :open="openGroups[g.title] !== false">
          <ul class="space-y-1">
            <li v-for="item in g.items" :key="item.to">
              <div class="relative flex items-center">
                <NuxtLink :to="item.to" class="flex flex-1 items-center gap-3 rounded-lg px-3 py-2 text-[0.9375rem] transition-colors hover:bg-black/[.04] dark:hover:bg-white/[.06]" :class="active(item.to) && 'nav-active'" :aria-current="active(item.to) ? 'page' : undefined">
                  <AcmeIcon v-if="item.icon" :name="item.icon" :size="22" class="text-ink/80" />
                  <span class="flex-1">{{ item.label }}</span>
                  <span v-if="item.children" class="w-6" />
                </NuxtLink>
                <button v-if="item.children" type="button" class="absolute right-3 rounded p-1 text-muted hover:text-ink" :aria-expanded="!!expanded[item.to]" :aria-label="t(':item sections', { item: item.label })" @click="expanded[item.to] = !expanded[item.to]">
                  <AcmeIcon name="chevronDown" :size="18" class="transition-transform" :class="expanded[item.to] && 'rotate-180'" />
                </button>
              </div>
              <AcmeCollapse v-if="item.children" :open="!!expanded[item.to]">
                <div class="ml-9 space-y-0.5 py-1">
                  <NuxtLink v-for="c in item.children" :key="c.to" :to="c.to" class="block rounded-md px-3 py-1.5 text-sm text-muted hover:bg-black/[.04] hover:text-ink dark:hover:bg-white/[.06]">{{ c.label }}</NuxtLink>
                </div>
              </AcmeCollapse>
            </li>
          </ul>
        </AcmeCollapse>
      </div>
    </div>
  </nav>
</template>
