<script setup lang="ts">
import type { ServiceOption } from '~/types/projects';

/** Create a project in three steps: what it is, which services it needs, and a check before creating it. */
const props = defineProps<{ services: ServiceOption[] }>();
const emit = defineEmits<{ created: [] }>();
const { t } = useT();
const route = useRoute();
const step = ref(0);
const busy = ref(false);
const error = ref<string | null>(null);
const errors = ref<ValidationError | null>(null);
const name = ref('');
const description = ref('');
// Links such as "Add Analytics" open the wizard with that service already chosen.
const preselected = route.query.services;
const chosen = ref<string[]>((Array.isArray(preselected) ? preselected : [preselected]).filter((key): key is string => typeof key === 'string' && props.services.some((service) => service.key === key)));

const steps = computed(() => [
    {
        id: 'details',
        title: t('Your project'),
        description: t('One project per app or site. It starts with a Production environment; you can add staging and others later.'),
        validate: () => (name.value.trim() === '' ? t('Give the project a name.') : null),
    },
    {
        id: 'services',
        title: t('What do you need?'),
        description: t('Use one service or several. Analytics and Monitoring work with any site, wherever it’s hosted, with no server here. You can change this later.'),
    },
    { id: 'review', title: t('Check and create') },
]);

const chosenNames = computed(() => props.services.filter((service) => chosen.value.includes(service.key)).map((service) => service.name).join(' · '));

async function create() {
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ redirect: string; message: string }>('POST', '/projects', { name: name.value, description: description.value || null, services: chosen.value });
        flash(result.message);
        emit('created');
        await navigateTo(local(result.redirect));
    } catch (problem) {
        if (problem instanceof ValidationError) {
            errors.value = problem;
            step.value = problem.first('name') || problem.first('description') ? 0 : 1;
        }
        error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
        busy.value = false;
    }
}
</script>

<template>
    <Wizard v-model="step" :steps="steps" :busy="busy" :error="error" :finish-label="t('Create project')" @finish="create">
        <template #details>
            <div class="grid gap-4">
                <UiField id="project-name" :label="t('Project name')" :error="errors?.first('name')">
                    <input id="project-name" v-model="name" class="ui-input" maxlength="100" autocomplete="off" required autofocus :aria-invalid="!!errors?.first('name') || undefined">
                </UiField>
                <UiField id="project-description" :label="t('Description')" :description="t('Optional. What this project is, for your teammates.')" :error="errors?.first('description')">
                    <textarea id="project-description" v-model="description" class="ui-input" rows="3" maxlength="500" aria-describedby="project-description-help" />
                </UiField>
            </div>
        </template>
        <template #services>
            <fieldset class="grid gap-2 sm:grid-cols-2">
                <legend class="sr-only">{{ t('Services') }}</legend>
                <label
                    v-for="service in services"
                    :key="service.key"
                    :class="['flex cursor-pointer items-start gap-3 rounded-card border p-4 transition', chosen.includes(service.key) ? 'border-primary bg-primary-soft' : 'border-line hover:border-subtle']"
                >
                    <input v-model="chosen" type="checkbox" class="ui-check mt-1" :value="service.key">
                    <span :class="[`product-icon-${service.key === 'monitoring' ? 'monitor' : service.key}`, 'grid size-9 shrink-0 place-items-center rounded-card']" aria-hidden="true">
                        <Icon :name="service.icon" class="size-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-extrabold text-ink">{{ service.name }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-muted">{{ service.tagline }}</span>
                    </span>
                </label>
            </fieldset>
        </template>
        <template #review>
            <dl class="grid gap-3 rounded-card bg-surface-muted p-4 text-sm sm:grid-cols-[9rem_1fr]">
                <dt class="font-bold text-muted">{{ t('Name') }}</dt>
                <dd class="text-ink">{{ name }}</dd>
                <template v-if="description">
                    <dt class="font-bold text-muted">{{ t('Description') }}</dt>
                    <dd class="text-ink">{{ description }}</dd>
                </template>
                <dt class="font-bold text-muted">{{ t('Services') }}</dt>
                <dd class="text-ink">{{ chosen.length ? chosenNames : t('None yet. You can turn them on from the project.') }}</dd>
            </dl>
        </template>
    </Wizard>
</template>
