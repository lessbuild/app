#!/usr/bin/env python3
"""Copy the Acme theme's generic components into web/app/components/acme, ready for BuildPusher.

Each component is copied from the theme, its references to the theme's other components get the Acme prefix (Nuxt
registers this folder as Acme*, see nuxt.config.ts), and the patches below are applied: English text goes through t(),
links take any route, dates use the visitor's locale and today's date. A patch that no longer matches stops the script,
so a change in the theme gets looked at rather than silently dropped.

Usage: scripts/sync-acme.py [path to the acme-theme checkout]
"""

import pathlib
import re
import sys

THEME = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '/root/Documents/acme-theme') / 'app' / 'components'
TARGET = pathlib.Path(__file__).resolve().parent.parent / 'web' / 'app' / 'components' / 'acme'

# Generic building blocks for showing things. Forms keep BuildPusher's own fields and dialogs (they post to the API and
# show its validation errors), styled like the theme's. Page-level components (PlatformPage, PlatformFinding, Audit*)
# are ported by hand, because they hold the theme's sample data; the theme's app chrome has BuildPusher equivalents.
COMPONENTS = '''
Accordion Alert AppTile Avatar AvatarGroup Badge BarChart BarList Breadcrumbs Btn Card ChipGroup CodeBlock Collapse Cover
DataTable DonutChart Drawer EmptyCard EmptyState Field FileIcon Gauge Icon IconBubble Kbd LineChart ListGroup ListRow
Menu Monogram PageBody PageHeader Pagination Progress SearchInput SectionTitle Segmented SettingRow SettingsHeading
Sparkline Stat StatusBadge Stepper SubNav Tabs Timeline Toggle UptimeBar
'''.split()

USE_T = 'const { t } = useT()'

# (component, old, new). "+t" as old adds `const { t } = useT()` to the script.
PATCHES = [
    ('Btn', "import { NuxtLink } from '#components'\n", "import type { RouteLocationRaw } from 'vue-router'\nimport { NuxtLink } from '#components'\n"),
    ('Btn', '  to?: string\n', '  to?: RouteLocationRaw\n'),
    ('Btn', "  props.size === 'sm' ?", "  props.to && (props.disabled || props.loading) && 'pointer-events-none opacity-50',\n  props.size === 'sm' ?"),
    ('Btn', ':disabled="disabled || loading || undefined"', ':disabled="!to && (disabled || loading) ? true : undefined" :aria-disabled="to && (disabled || loading) ? true : undefined"'),

    ('DataTable', '+t', ''),
    ('DataTable', "{ rowKey: 'id', searchPlaceholder: 'Search…', emptyTitle: 'Nothing here yet' })", "{ rowKey: 'id', searchPlaceholder: undefined, emptyTitle: undefined })"),
    ('DataTable', ':label="searchPlaceholder" hide-label icon="search" :placeholder="searchPlaceholder"', ":label=\"searchPlaceholder ?? t('Search…')\" hide-label icon=\"search\" :placeholder=\"searchPlaceholder ?? t('Search…')\""),
    ('DataTable', ":title=\"query ? 'No matches' : emptyTitle\" :description=\"query ? `Nothing matches “${query}”.` : undefined\"", ":title=\"query ? t('No matches') : (emptyTitle ?? t('Nothing here yet'))\" :description=\"query ? t('Nothing matches “:query”.', { query }) : undefined\""),
    ('DataTable', "@click=\"query = ''\">Clear search</AcmeBtn>", "@click=\"query = ''\">{{ t('Clear search') }}</AcmeBtn>"),

    ('SearchInput', "withDefaults(defineProps<{ label?: string; placeholder?: string }>(), { label: 'Search' })", "const props = defineProps<{ label?: string; placeholder?: string }>()\nconst { t } = useT()\nconst name = computed(() => props.label ?? t('Search'))"),
    ('SearchInput', ':label hide-label icon="search" :placeholder="placeholder ?? `${label}…`"', ':label="name" hide-label icon="search" :placeholder="placeholder ?? `${name}…`"'),
    ('SearchInput', 'aria-label="Clear search"', ":aria-label=\"t('Clear search')\""),

    ('CodeBlock', "const items = computed(() => props.tabs ?? [{ label: props.title ?? 'Code', code: props.code ?? '' }])", "const { t } = useT()\nconst items = computed(() => props.tabs ?? [{ label: props.title ?? t('Code'), code: props.code ?? '' }])"),
    ('CodeBlock', '  try { await navigator.clipboard.writeText(items.value[active.value]!.code) } catch {}\n', '  // Only say "Copied" when the browser let us copy.\n  const done = await navigator.clipboard.writeText(items.value[active.value]!.code).then(() => true, () => false)\n  if (!done) return\n'),
    ('CodeBlock', 'aria-label="Language"', ":aria-label=\"t('Language')\""),
    ('CodeBlock', 'v-for="(t, i) in items" :key="t.label"', 'v-for="(tab, i) in items" :key="tab.label"'),
    ('CodeBlock', '@click="active = i">{{ t.label }}</button>', '@click="active = i">{{ tab.label }}</button>'),
    ('CodeBlock', ":aria-label=\"copied ? 'Copied' : 'Copy code'\"", ":aria-label=\"copied ? t('Copied') : t('Copy code')\""),
    ('CodeBlock', "{{ copied ? 'Copied' : 'Copy' }}", "{{ copied ? t('Copied') : t('Copy') }}"),

    ('Alert', '+t', ''),
    ('Alert', 'aria-label="Dismiss"', ":aria-label=\"t('Dismiss')\""),
    ('SubNav', '+t', ''),
    ('SubNav', ':aria-label="`${item.label} sections`"', ":aria-label=\"t(':item sections', { item: item.label })\""),
    ('Pagination', '+t', ''),
    ('Pagination', 'aria-label="Pagination"', ":aria-label=\"t('Pagination')\""),
    ('Pagination', '<p class="text-muted">Showing <b class="font-medium text-ink">{{ from }}–{{ to }}</b> of <b class="font-medium text-ink">{{ total }}</b></p>', "<p class=\"text-muted\">{{ t('Showing :from–:to of :total', { from, to, total }) }}</p>"),
    ('Pagination', 'label="Previous page"', ":label=\"t('Previous page')\""),
    ('Pagination', 'label="Next page"', ":label=\"t('Next page')\""),
    ('Breadcrumbs', '+t', ''),
    ('Breadcrumbs', 'aria-label="Breadcrumb"', ":aria-label=\"t('Breadcrumb')\""),

    ('UptimeBar', "const dayLabel = (i: number) => {\n  // Sub-day strips end at the demo's \"now\" (14:30 UTC) rather than midnight.\n  const end = TODAY.getTime() + (daily.value ? 0 : 14.5 * 36e5)\n  const d = new Date(end - (props.days.length - 1 - i) * props.step * 6e4)\n  return daily.value ? d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })\n}",
     "const { t, locale } = useT()\n// The last bar is now (or today); the others step back from it.\nconst dayLabel = (i: number) => {\n  const d = new Date(Date.now() - (props.days.length - 1 - i) * props.step * 6e4)\n  return daily.value ? d.toLocaleDateString(locale.value, { month: 'short', day: 'numeric' }) : d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })\n}\nconst states = computed(() => ({ up: t('No incidents'), degraded: t('Degraded'), down: t('Outage') }))"),
    ('UptimeBar', "  return mins >= 2880 ? `${Math.round(mins / 1440)} days` : `${Math.round(mins / 60)} hours`", "  return mins >= 2880 ? t(':count days', { count: Math.round(mins / 1440) }) : t(':count hours', { count: Math.round(mins / 60) })"),
    ('UptimeBar', ":aria-label=\"`${label}: ${pct}% uptime over ${span}`\"", ":aria-label=\"t(':label: :pct% uptime over :span', { label, pct, span })\""),
    ('UptimeBar', "{{ { up: 'No incidents', degraded: 'Degraded', down: 'Outage' }[days[hover]!] }}", '{{ states[days[hover]!] }}'),
    ('UptimeBar', "<span>{{ span }} ago</span><span>{{ pct }}% uptime</span><span>{{ daily ? 'Today' : 'Now' }}</span>", "<span>{{ t(':span ago', { span }) }}</span><span>{{ t(':pct% uptime', { pct }) }}</span><span>{{ daily ? t('Today') : t('Now') }}</span>"),

    ('Drawer', '+t', ''),
    ('Drawer', 'label="Close panel"', ":label=\"t('Close panel')\""),
    ('DonutChart', '+t', ''),
    ('DonutChart', "{{ hover === null ? 'Total' : data[hover]!.label }}", "{{ hover === null ? t('Total') : data[hover]!.label }}"),
    ('LineChart', '+t', ''),
    ('LineChart', '<th>Period</th>', "<th>{{ t('Period') }}</th>"),
    ('BarList', "{ valueLabel: 'Visitors' })", '{ valueLabel: undefined })'),
    ('BarList', "const fmt = (v: number) => (props.format ? props.format(v) : v.toLocaleString('en-US'))", "const { t, number } = useT()\nconst fmt = (v: number) => (props.format ? props.format(v) : number(v))"),
    ('BarList', '<span>{{ valueLabel }}</span>', "<span>{{ valueLabel ?? t('Visitors') }}</span>"),
]


def main() -> None:
    names = [name for name in COMPONENTS if (THEME / f'{name}.vue').exists()]
    missing = sorted(set(COMPONENTS) - set(names))
    if missing:
        sys.exit(f'Missing from the theme: {", ".join(missing)}')
    TARGET.mkdir(parents=True, exist_ok=True)
    sources = {name: (THEME / f'{name}.vue').read_text() for name in names}
    for name, source in sources.items():
        for other in names:
            source = re.sub(rf'<(/?){other}(?=[\s>/])', rf'<\1Acme{other}', source)
        sources[name] = source
    for name, old, new in PATCHES:
        source = sources[name]
        if old == '+t':
            if 'useT()' not in source:
                source = source.replace('</script>', f'{USE_T}\n</script>', 1)
        elif old not in source:
            sys.exit(f'{name}: patch no longer matches: {old[:80]}')
        else:
            source = source.replace(old, new)
        sources[name] = source
    for name, source in sources.items():
        (TARGET / f'{name}.vue').write_text(source)
    print(f'Synced {len(sources)} components into {TARGET}')


if __name__ == '__main__':
    main()
