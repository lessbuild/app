<script setup lang="ts">
import type { Journey } from '~/types/audit';

/** A journey replayed: how it ended, the friction, and every step with its screen and the visitor's reasoning. */
defineProps<{ journey: Journey; screen: { width: number; height: number } }>();
const { t, tc } = useT();
const tone = { succeeded: 'success', struggled: 'warning', failed: 'danger' } as const;
const verbs = computed<Record<string, string>>(() => ({
    click: t('Clicked'), type: t('Typed'), select: t('Chose'), press_enter: t('Pressed Enter'),
    scroll: t('Scrolled'), back: t('Went back'), finish: t('Finished'), give_up: t('Gave up'),
}));
</script>

<template>
    <article class="grid gap-6">
        <div class="flex flex-wrap items-center gap-2 text-sm text-muted">
            <AcmeBadge :tone="acmeTone(tone[journey.outcome])">{{ journey.outcomeLabel }}</AcmeBadge>
            <span>{{ journey.siteName }}</span><span aria-hidden="true">·</span>
            <span>{{ tc(':count step|:count steps', journey.stepsCount, { count: journey.stepsCount }) }}</span><span aria-hidden="true">·</span>
            <span>{{ t(':seconds s', { seconds: journey.seconds }) }}</span>
        </div>
        <blockquote v-if="journey.summary" class="border-l-4 border-line pl-4 text-sm italic leading-6 text-ink">{{ journey.summary }}</blockquote>
        <section v-if="journey.friction.length > 0" class="grid gap-2">
            <h3 class="text-sm font-semibold text-ink">{{ t('What slowed the visitor down') }}</h3>
            <ul class="grid list-disc gap-1 pl-5 text-sm text-muted"><li v-for="item in journey.friction" :key="item">{{ item }}</li></ul>
        </section>
        <ol class="grid gap-5">
            <li v-for="step in journey.steps" :key="step.position" class="grid gap-3 border-t border-line pt-5 md:grid-cols-[16rem_1fr]">
                <div class="grid content-start gap-1">
                    <p class="ui-eyebrow">{{ t('Step :number', { number: step.position }) }}</p>
                    <p class="text-sm font-bold text-ink">
                        {{ verbs[step.action.type] ?? step.action.type }}<template v-if="step.action.label"> “{{ step.action.label }}”</template><template v-if="step.action.text"> — “{{ step.action.text }}”</template>
                    </p>
                    <p v-if="step.thought" class="text-sm leading-6 text-muted">{{ step.thought }}</p>
                    <p class="truncate text-xs text-subtle">{{ step.url }}</p>
                </div>
                <AuditScreenshot v-if="step.screenshotUrl" :src="step.screenshotUrl" :alt="t('The screen at step :number', { number: step.position })" :boxes="step.boxes" :screen="screen" />
            </li>
        </ol>
    </article>
</template>
