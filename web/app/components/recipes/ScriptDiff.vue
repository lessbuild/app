<script setup lang="ts">
/**
 * The lines that differ between two versions of a script, as a unified diff: removed lines red with "−", added lines
 * green with "+", and a few unchanged lines around each change for context.
 */
const props = withDefaults(defineProps<{ before: string; after: string; context?: number }>(), { context: 2 });
const { t, tc } = useT();
type Line = { kind: 'same' | 'removed' | 'added' | 'gap'; text: string };

/**
 * Compare two scripts line by line (longest common subsequence), keeping only the changes and their context.
 *
 * @param before The current version.
 * @param after The other version.
 * @param context How many unchanged lines to keep around each change.
 */
function diff(before: string, after: string, context: number): Line[] {
    const a = before.split('\n');
    const b = after.split('\n');
    if (a.length * b.length > 4_000_000) {
        return [...a.map((text): Line => ({ kind: 'removed', text })), ...b.map((text): Line => ({ kind: 'added', text }))];
    }
    const table: number[][] = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));
    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i]![j] = a[i] === b[j] ? table[i + 1]![j + 1]! + 1 : Math.max(table[i + 1]![j]!, table[i]![j + 1]!);
        }
    }
    const lines: Line[] = [];
    let i = 0;
    let j = 0;
    while (i < a.length || j < b.length) {
        if (i < a.length && j < b.length && a[i] === b[j]) {
            lines.push({ kind: 'same', text: a[i]! });
            i++;
            j++;
        } else if (j < b.length && (i >= a.length || table[i]![j + 1]! >= table[i + 1]![j]!)) {
            lines.push({ kind: 'added', text: b[j]! });
            j++;
        } else {
            lines.push({ kind: 'removed', text: a[i]! });
            i++;
        }
    }
    const keep = lines.map((line, index) => line.kind !== 'same' || lines.slice(Math.max(0, index - context), index + context + 1).some((near) => near.kind !== 'same'));
    const shown: Line[] = [];
    lines.forEach((line, index) => {
        if (keep[index]) {
            shown.push(line);
        } else if (shown.at(-1)?.kind !== 'gap') {
            shown.push({ kind: 'gap', text: '' });
        }
    });

    return shown;
}

const lines = computed(() => diff(props.before, props.after, props.context));
const added = computed(() => lines.value.filter((line) => line.kind === 'added').length);
const removed = computed(() => lines.value.filter((line) => line.kind === 'removed').length);
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-line">
        <p class="border-b border-line bg-black/[.02] px-3 py-2 text-xs text-muted dark:bg-white/[.03]">
            <span class="text-emerald-700 dark:text-emerald-300">{{ tc(':count line added|:count lines added', added) }}</span> ·
            <span class="text-rose-700 dark:text-rose-300">{{ tc(':count line removed|:count lines removed', removed) }}</span>
        </p>
        <p v-if="added === 0 && removed === 0" class="p-3 text-sm text-muted">{{ t('The scripts are the same.') }}</p>
        <pre v-else class="max-h-96 overflow-auto text-xs leading-relaxed"><code class="block font-mono"><template v-for="(line, index) in lines" :key="index"><span v-if="line.kind === 'gap'" class="block bg-black/[.03] px-3 text-muted dark:bg-white/[.04]">…</span><span v-else :class="['block whitespace-pre px-3', line.kind === 'added' ? 'bg-emerald-500/10 text-emerald-900 dark:text-emerald-200' : line.kind === 'removed' ? 'bg-rose-500/10 text-rose-900 dark:text-rose-200' : 'text-ink']"><span class="mr-2 select-none text-muted" aria-hidden="true">{{ line.kind === 'added' ? '+' : line.kind === 'removed' ? '−' : ' ' }}</span><span class="sr-only">{{ line.kind === 'added' ? t('Added:') : line.kind === 'removed' ? t('Removed:') : '' }}</span>{{ line.text }}</span></template></code></pre>
    </div>
</template>
