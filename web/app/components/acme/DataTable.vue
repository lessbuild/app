<script setup lang="ts" generic="T extends Record<string, any>">
// Table with optional search, sortable columns, pagination, loading skeleton and empty state.
// Use a `cell-<key>` slot to customise a column; `sortValue` sorts by something other than the raw field.
export interface Column<R> { key: string; label: string; class?: string; sortable?: boolean; sortValue?: (row: R) => string | number; srOnly?: boolean }

const props = withDefaults(defineProps<{
  columns: Column<T>[]
  rows: T[]
  rowKey?: string
  search?: string[]
  searchPlaceholder?: string
  pageSize?: number
  loading?: boolean
  caption?: string
  emptyTitle?: string
}>(), { rowKey: 'id', searchPlaceholder: undefined, emptyTitle: undefined })

const query = ref('')
const sortKey = ref<string | null>(null)
const sortDir = ref<1 | -1>(1)
const page = ref(1)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q || !props.search) return props.rows
  return props.rows.filter(r => props.search!.some(k => String(r[k] ?? '').toLowerCase().includes(q)))
})
const sorted = computed(() => {
  const col = props.columns.find(c => c.key === sortKey.value)
  if (!col) return filtered.value
  const val = col.sortValue ?? ((r: T) => r[col.key])
  return [...filtered.value].sort((a, b) => {
    const x = val(a), y = val(b)
    return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y))) * sortDir.value
  })
})
const visible = computed(() => (props.pageSize ? sorted.value.slice((page.value - 1) * props.pageSize, page.value * props.pageSize) : sorted.value))
watch([query, () => props.rows.length], () => (page.value = 1))

function sortBy(key: string) {
  if (sortKey.value === key) sortDir.value = sortDir.value === 1 ? -1 : 1
  else { sortKey.value = key; sortDir.value = 1 }
}
const ariaSort = (key: string) => (sortKey.value === key ? (sortDir.value === 1 ? 'ascending' : 'descending') : 'none')
const { t } = useT()
</script>

<template>
  <div class="space-y-3">
    <div v-if="search || $slots.toolbar" class="flex flex-wrap items-center gap-2">
      <AcmeField v-if="search" v-model="query" :label="searchPlaceholder ?? t('Search…')" hide-label icon="search" :placeholder="searchPlaceholder ?? t('Search…')" type="search" class="w-full sm:w-72" />
      <div class="ml-auto flex gap-2"><slot name="toolbar" /></div>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-line bg-surface shadow-card">
      <table class="w-full text-left text-sm">
        <caption v-if="caption" class="sr-only">{{ caption }}</caption>
        <thead class="border-b border-line bg-black/[.015] text-xs uppercase tracking-wide text-muted dark:bg-white/[.02]">
          <tr>
            <th v-for="c in columns" :key="c.key" scope="col" class="whitespace-nowrap px-4 py-3 font-medium" :class="c.class" :aria-sort="c.sortable ? ariaSort(c.key) : undefined">
              <span v-if="c.srOnly" class="sr-only">{{ c.label }}</span>
              <button v-else-if="c.sortable" type="button" class="-mx-1 inline-flex items-center gap-1 rounded px-1 hover:text-ink" @click="sortBy(c.key)">
                {{ c.label }}
                <AcmeIcon :name="sortKey === c.key ? (sortDir === 1 ? 'sortUp' : 'sortDown') : 'sort'" :size="14" :class="sortKey !== c.key && 'opacity-40'" />
              </button>
              <template v-else>{{ c.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <template v-if="loading">
            <tr v-for="n in Math.min(pageSize ?? 5, 5)" :key="n">
              <td v-for="c in columns" :key="c.key" class="px-4 py-3.5"><div class="skeleton h-4" :style="{ width: `${50 + ((n * 17 + c.key.length * 7) % 45)}%` }" /></td>
            </tr>
          </template>
          <tr v-else-if="!visible.length">
            <td :colspan="columns.length">
              <AcmeEmptyState icon="search" :title="query ? t('No matches') : (emptyTitle ?? t('Nothing here yet'))" :description="query ? t('Nothing matches “:query”.', { query }) : undefined">
                <AcmeBtn v-if="query" size="sm" @click="query = ''">{{ t('Clear search') }}</AcmeBtn>
              </AcmeEmptyState>
            </td>
          </tr>
          <tr v-for="row in visible" v-else :key="row[rowKey]" class="transition-colors hover:bg-black/[.02] dark:hover:bg-white/[.03]">
            <td v-for="c in columns" :key="c.key" class="px-4 py-3.5" :class="c.class">
              <slot :name="`cell-${c.key}`" :row="row">{{ row[c.key] }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <AcmePagination v-if="pageSize && sorted.length > pageSize" v-model="page" :total="sorted.length" :page-size="pageSize" />
  </div>
</template>
