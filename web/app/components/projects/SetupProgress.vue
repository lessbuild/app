<script setup lang="ts">
import type { SetupStep } from '~/types/projects';

/** The setup steps as a row of numbered dots joined by lines, done ones ticked, the current one ringed. */
const props = defineProps<{ steps: SetupStep[] }>();
const { t } = useT();
const current = computed(() => props.steps.find((step) => step.state !== 'done')?.key ?? null);
const labels = computed<Record<SetupStep['state'], string>>(() => ({ done: t('Done'), working: t('In progress'), todo: t('To do') }));
</script>

<template>
    <ol class="flex items-center" :aria-label="t('Setup progress')">
        <li v-for="(step, index) in steps" :key="step.key" :class="['flex items-center', index < steps.length - 1 && 'flex-1']" :aria-current="step.key === current ? 'step' : undefined">
            <span
                :class="[
                    'grid size-8 shrink-0 place-items-center rounded-full border-2 text-xs font-extrabold sm:size-9',
                    step.state === 'done' ? 'border-success bg-success text-white' : step.key === current ? 'border-primary bg-primary-soft text-primary ring-4 ring-primary/15' : 'border-line bg-surface text-muted',
                ]"
                :title="step.title"
            >
                <Icon v-if="step.state === 'done'" name="check" class="size-4" />
                <template v-else>{{ index + 1 }}</template>
                <span class="sr-only">{{ step.title }} ({{ labels[step.state] }})</span>
            </span>
            <span v-if="index < steps.length - 1" :class="['mx-1 h-0.5 flex-1 rounded-full sm:mx-2', step.state === 'done' ? 'bg-success' : 'bg-line']" aria-hidden="true" />
        </li>
    </ol>
</template>
