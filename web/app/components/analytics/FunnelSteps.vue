<script setup lang="ts">
import type { FunnelStep } from '~/types/analytics';

/**
 * A funnel's steps, two to six: each a page or a custom event, matched exactly or by how it starts. Named as Laravel
 * expects (steps[0][kind] and so on), so it works inside a form, and editable through v-model inside a wizard.
 */
const steps = defineModel<FunnelStep[]>({ required: true });
defineProps<{ id: string }>();
const { t } = useT();
const kinds = computed(() => [{ value: 'pageview', label: t('Page') }, { value: 'event', label: t('Custom event') }]);
const matches = computed(() => [{ value: 'exact', label: t('Exactly') }, { value: 'prefix', label: t('Starts with') }]);
</script>

<template>
    <ol class="grid gap-3">
        <li v-for="(step, index) in steps" :key="index" class="grid items-end gap-3 rounded-panel border border-line p-3 sm:grid-cols-[9rem_9rem_minmax(0,1fr)_auto]">
            <SelectField :id="`${id}-kind-${index}`" v-model="step.kind" :name="`steps[${index}][kind]`" :label="t('Step :number', { number: index + 1 })" :options="kinds" />
            <SelectField :id="`${id}-match-${index}`" v-model="step.match" :name="`steps[${index}][match]`" :label="t('Match')" :options="matches" />
            <InputField :id="`${id}-value-${index}`" v-model="step.value" :name="`steps[${index}][value]`" :label="step.kind === 'event' ? t('Event name') : t('Path')" :placeholder="index === 0 ? '/pricing' : ''" maxlength="255" />
            <AcmeBtn v-if="steps.length > 2" variant="quiet" size="sm" :aria-label="t('Remove step :number', { number: index + 1 })" @click="steps.splice(index, 1)"><Icon name="x" class="h-4 w-4" /></AcmeBtn>
        </li>
    </ol>
    <div v-if="steps.length < 6"><AcmeBtn variant="secondary" size="sm" @click="steps.push({ kind: 'pageview', match: 'exact', value: '' })" icon="plus">{{ t('Add a step') }}</AcmeBtn></div>
</template>
