<script setup lang="ts">
import type { FunnelStep } from '~/types/analytics';

/** Adding a funnel in three steps: name it, set the steps visitors take in order, then check and add it. */
const props = defineProps<{ action: string }>();
const emit = defineEmits<{ done: [] }>();
const { t } = useT();
const step = ref(0);
const busy = ref(false);
const error = ref<string | null>(null);
const name = ref('');
const steps = ref<FunnelStep[]>([{ kind: 'pageview', match: 'exact', value: '/' }, { kind: 'pageview', match: 'exact', value: '' }]);
const filled = computed(() => steps.value.filter((item) => item.value.trim() !== ''));
const wizardSteps = computed(() => [
    { id: 'name', title: t('Name'), description: t('What visitors are trying to do, such as Checkout or Sign-up.'), validate: () => (name.value.trim() === '' ? t('Give the funnel a name.') : null) },
    { id: 'steps', title: t('Steps'), description: t('Two to six steps. A visitor counts at a step once they’ve done every step before it, in order.'), validate: () => (filled.value.length < 2 ? t('Add at least two steps.') : null) },
    { id: 'review', title: t('Check and add') },
]);
const describe = (item: FunnelStep) => `${item.kind === 'event' ? t('Custom event') : t('Page')} ${item.match === 'prefix' ? t('starts with') : t('exactly')} ${item.value}`;

/** Add the funnel and show it with the others. */
async function add() {
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ message: string }>('POST', props.action, { name: name.value, steps: filled.value });
        flash(result.message);
        emit('done');
        await refreshPage();
    } catch (problem) {
        error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
    }
    busy.value = false;
}
</script>

<template>
    <Wizard v-model="step" :steps="wizardSteps" :busy="busy" :error="error" :finish-label="t('Add funnel')" @finish="add">
        <template #name>
            <UiField id="funnel-name" :label="t('Name')">
                <input id="funnel-name" v-model="name" class="ui-input" maxlength="120" placeholder="Checkout" required autofocus>
            </UiField>
        </template>
        <template #steps>
            <div class="grid gap-3"><FunnelSteps id="new-funnel" v-model="steps" /></div>
        </template>
        <template #review>
            <p class="font-extrabold text-ink">{{ name }}</p>
            <ol class="mt-2 grid list-decimal gap-1 ps-5 text-sm text-muted">
                <li v-for="(item, index) in filled" :key="index">{{ describe(item) }}</li>
            </ol>
        </template>
    </Wizard>
</template>
