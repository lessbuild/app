<script setup lang="ts">
/**
 * Which services call which, drawn as a map (the Acme theme's service map): each service is placed in a column after the
 * services that call it, with a curve for each call. Its dot is amber or red when its error rate is over 1% or 5%.
 */
const props = defineProps<{
    services: Array<{ name: string; error_rate: number }>;
    edges: Array<{ source: string; target: string }>;
    label: string;
}>();
const width = 180;
const gap = 70;
const rowHeight = 64;

// Each service's column is one more than the deepest service calling it (cycles stop at what's been placed).
const layout = computed(() => {
    const names = [...new Set([...props.services.map((service) => service.name), ...props.edges.flatMap((edge) => [edge.source, edge.target])])];
    const depth = new Map<string, number>(names.map((name) => [name, 0]));
    for (let pass = 0; pass < names.length; pass++) {
        let changed = false;
        for (const edge of props.edges) {
            const next = (depth.get(edge.source) ?? 0) + 1;
            if (edge.source !== edge.target && next > (depth.get(edge.target) ?? 0) && next < names.length) {
                depth.set(edge.target, next);
                changed = true;
            }
        }
        if (!changed) {
            break;
        }
    }
    const columns = new Map<number, string[]>();
    for (const name of names) {
        columns.set(depth.get(name) ?? 0, [...(columns.get(depth.get(name) ?? 0) ?? []), name]);
    }
    const rows = Math.max(1, ...[...columns.values()].map((column) => column.length));
    const position = new Map<string, { x: number; y: number }>();
    for (const [column, members] of columns) {
        members.forEach((name, row) => position.set(name, { x: 20 + column * (width + gap), y: 30 + (row + (rows - members.length) / 2) * rowHeight }));
    }
    return { position, width: 40 + (Math.max(0, ...columns.keys()) + 1) * (width + gap) - gap, height: 20 + rows * rowHeight };
});
const rate = (name: string) => props.services.find((service) => service.name === name)?.error_rate ?? 0;
const dot = (name: string) => (rate(name) > 5 ? 'fill-rose-500' : rate(name) > 1 ? 'fill-amber-500' : 'fill-emerald-500');

/**
 * The curve from one service's box to another's.
 *
 * @param edge The call.
 */
function path(edge: { source: string; target: string }): string {
    const from = layout.value.position.get(edge.source);
    const to = layout.value.position.get(edge.target);
    if (!from || !to) {
        return '';
    }
    const [x1, y1, x2, y2] = [from.x + width, from.y, to.x, to.y];
    const bend = Math.max(40, (x2 - x1) / 2);
    return `M${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}`;
}
</script>

<template>
    <div class="hatch overflow-x-auto rounded-xl border border-line p-4">
        <svg :viewBox="`0 0 ${layout.width} ${layout.height}`" :style="{ minWidth: `${Math.min(layout.width, 900)}px` }" class="mx-auto block w-full" role="img" :aria-label="label">
            <g class="stroke-line" stroke-width="2" fill="none">
                <path v-for="edge in edges" :key="`${edge.source}->${edge.target}`" :d="path(edge)" />
            </g>
            <g v-for="[name, point] in layout.position" :key="name" :transform="`translate(${point.x}, ${point.y})`">
                <rect x="0" y="-20" :width="width" height="40" rx="10" class="fill-surface stroke-line" stroke-width="1.5" />
                <circle cx="16" cy="0" r="4" :class="dot(name)" />
                <text x="28" y="4" class="fill-ink text-[12px] font-medium">{{ name.length > 22 ? `${name.slice(0, 21)}…` : name }}</text>
            </g>
        </svg>
    </div>
</template>
