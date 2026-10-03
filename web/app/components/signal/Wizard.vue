<script setup lang="ts">
/**
 * A task split into steps that build on each other: a numbered list of the steps, one step at a time (each step's
 * content is the slot named after its id), Back and Continue, and the finishing action on the last step. Focus moves
 * to each new step's heading for screen readers.
 */
const props = defineProps<{
    steps: Array<{
        id: string;
        title: string;
        description?: string;
        /** Check the step before moving on; return an error message to stay on it. */
        validate?: () => string | null;
    }>;
    finishLabel: string;
    busy?: boolean;
    error?: string | null;
}>();
const current = defineModel<number>({ required: true });
const emit = defineEmits<{ finish: [] }>();
const { t } = useT();
const heading = ref<HTMLHeadingElement | null>(null);
const headingId = useId();
const stepError = ref<string | null>(null);
const step = computed(() => props.steps[current.value]);
const last = computed(() => current.value === props.steps.length - 1);

/** Go to a step and move focus to its heading. */
function go(index: number) {
    stepError.value = null;
    current.value = index;
    nextTick(() => heading.value?.focus());
}

/** Check the step, then go on or finish. */
function next() {
    const problem = step.value?.validate?.() ?? null;
    if (problem) {
        stepError.value = problem;
        return;
    }
    if (last.value) {
        emit('finish');
    } else {
        go(current.value + 1);
    }
}
</script>

<template>
    <form v-if="step" class="grid gap-6" :aria-labelledby="headingId" @submit.prevent="next">
        <ol class="flex flex-wrap items-center gap-2 text-xs font-bold" :aria-label="t('Steps')">
            <li v-for="(item, index) in steps" :key="item.id" class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="index > current || busy"
                    :class="['flex items-center gap-2 rounded-pill px-2.5 py-1 transition', index === current ? 'bg-primary-soft text-primary' : index < current ? 'text-ink hover:bg-surface-muted' : 'text-subtle']"
                    :aria-current="index === current ? 'step' : undefined"
                    @click="go(index)"
                >
                    <span :class="['grid size-5 place-items-center rounded-full text-[11px]', index < current ? 'bg-primary text-on-primary' : 'border border-current']" aria-hidden="true">
                        {{ index < current ? '✓' : index + 1 }}
                    </span>
                    {{ item.title }}
                </button>
                <span v-if="index < steps.length - 1" class="h-px w-4 bg-line" aria-hidden="true" />
            </li>
        </ol>

        <section class="grid gap-5">
            <div>
                <p class="ui-eyebrow">{{ t('Step :current of :total', { current: current + 1, total: steps.length }) }}</p>
                <h3 :id="headingId" ref="heading" tabindex="-1" class="mt-1 text-xl font-extrabold tracking-tight text-ink outline-none">{{ step.title }}</h3>
                <p v-if="step.description" class="mt-1 text-sm text-muted">{{ step.description }}</p>
            </div>
            <slot :name="step.id" />
            <p v-if="stepError || error" class="ui-error" role="alert">{{ stepError ?? error }}</p>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
            <UiButton variant="quiet" :disabled="current === 0 || busy" @click="go(current - 1)">{{ t('Back') }}</UiButton>
            <UiButton type="submit" variant="primary" :disabled="busy" :aria-busy="busy || undefined">
                {{ busy ? t('Working…') : last ? finishLabel : t('Continue') }}
            </UiButton>
        </div>
    </form>
</template>
