<script setup lang="ts">
import type { AuditDetail, AuditPlan, Competitor, Goal } from '~/types/audit';

/**
 * Set up an audit in four steps (the site, the tasks the visitor tries, the competitors, then the schedule and a
 * review), or change one. Used in the "New audit" and "Edit" dialogs. Errors from the API take people back to the
 * step their field is on.
 */
const props = defineProps<{ projectId: string; plan: AuditPlan; goals: Goal[]; audit?: AuditDetail | null }>();
const emit = defineEmits<{ done: [] }>();
type Suggestion = { name: string; url: string; reason: string };
const { t, tc } = useT();
const step = ref(0);
const busy = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<ValidationError | null>(null);
const name = ref(props.audit?.name ?? '');
const url = ref(props.audit?.url ?? '');
const chosen = ref<string[]>(props.audit ? props.audit.journeys.filter((journey) => journey.key !== 'custom').map((journey) => journey.key) : ['understand', 'pricing', 'sign_up']);
const customGoal = ref(props.audit?.journeys.find((journey) => journey.key === 'custom')?.goal ?? '');
const competitors = ref<Competitor[]>(props.audit?.competitors ?? []);
const newCompetitor = ref('');
const suggestions = ref<Suggestion[] | null>(null);
const suggesting = ref(false);
const suggestError = ref<string | null>(null);
const schedule = ref(props.audit?.schedule ?? 'none');
// Which step each field belongs to, so an error from Laravel takes people back to the right step.
const stepOfField: Record<string, number> = { name: 0, url: 0, goals: 1, custom_goal: 1, competitors: 2, schedule: 3, audit: 3 };
const limit = computed(() => props.plan.competitorLimit);
const atLimit = computed(() => limit.value !== null && competitors.value.length >= limit.value);
const taskCount = computed(() => chosen.value.length + (customGoal.value.trim() ? 1 : 0));
const fieldError = (field: string) => fieldErrors.value?.first(field);
const host = (value: string) => value.replace(/^https?:\/\//, '').replace(/\/.*$/, '').toLowerCase();
const schedules = computed(() => [
    { value: 'none', label: t('Only when I run it'), plan: null },
    { value: 'monthly', label: t('Every month'), plan: t('Pro plan') },
    { value: 'weekly', label: t('Every week'), plan: t('Business plan') },
]);
const goalChoices = computed(() => props.goals.filter((goal) => goal.value !== 'custom'));
const steps = computed(() => [
    { id: 'site', title: t('Your site'), description: t('The page the visitor starts on, usually your home page.'), validate: () => (url.value.trim() === '' ? t('Enter your site’s address.') : null) },
    {
        id: 'tasks', title: t('What should the visitor try?'), description: t('Pick up to five tasks. The visitor tries each one on your site and on every competitor’s.'),
        validate: () => (taskCount.value === 0 ? t('Choose at least one task.') : taskCount.value > 5 ? t('Choose at most five tasks.') : null),
    },
    {
        id: 'competitors', title: t('Who do people compare you with?'),
        description: limit.value === null ? t('Add the sites your visitors weigh you against. The same tasks run on each.') : tc('Your plan compares with up to :count competitor.|Your plan compares with up to :count competitors.', limit.value, { count: limit.value }),
    },
    { id: 'review', title: t('Schedule and review'), description: t('Check the details, then start the audit. The first report takes a few minutes.') },
]);

/** Add a competitor unless it's already there, is the site itself, or the plan's limit is reached. */
function addCompetitor(competitor: Competitor) {
    if (atLimit.value || competitors.value.some((existing) => host(existing.url) === host(competitor.url)) || host(competitor.url) === host(url.value)) {
        return;
    }
    competitors.value = [...competitors.value, competitor];
}

/** Add the typed address as a competitor. */
function addTyped() {
    const value = newCompetitor.value.trim();
    if (value) {
        addCompetitor({ url: value, name: host(value), source: 'customer', reason: null });
        newCompetitor.value = '';
    }
}

/** Ask for competitors of the site. */
async function suggest() {
    suggesting.value = true;
    suggestError.value = null;
    try {
        suggestions.value = (await send<{ competitors: Suggestion[] }>('POST', `/projects/${props.projectId}/audit/competitor-suggestions`, { url: url.value })).competitors;
    } catch (problem) {
        suggestError.value = problem instanceof ValidationError ? (problem.first('url') ?? problem.message) : t('Suggestions aren’t available right now. Add competitors yourself, or try again.');
    }
    suggesting.value = false;
}

/** Save the audit; a new one starts its first run, and the app goes to it. */
async function finish() {
    busy.value = true;
    error.value = null;
    fieldErrors.value = null;
    const body = {
        name: name.value, url: url.value, goals: chosen.value, custom_goal: customGoal.value.trim() || null, schedule: schedule.value,
        competitors: competitors.value.map((competitor) => ({ url: competitor.url, name: competitor.name, source: competitor.source ?? 'customer', reason: competitor.reason })),
    };
    try {
        if (props.audit) {
            await send('PUT', `/projects/${props.projectId}/audit/${props.audit.id}`, body);
            flash(t('Audit saved.'));
            emit('done');
            await refreshPage();
        } else {
            const created = await send<{ audit: AuditDetail; runId: number | null }>('POST', `/projects/${props.projectId}/audit`, body);
            emit('done');
            await navigateTo(created.runId ? `/projects/${props.projectId}/audit/runs/${created.runId}` : `/projects/${props.projectId}/audit/${created.audit.id}`);
        }
    } catch (problem) {
        if (problem instanceof ValidationError) {
            fieldErrors.value = problem;
            const first = Object.keys(problem.errors)[0]?.split('.')[0] ?? 'audit';
            step.value = stepOfField[first] ?? 3;
            error.value = problem.message;
        } else {
            error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
        }
    }
    busy.value = false;
}
</script>

<template>
    <Wizard v-model="step" :steps="steps" :busy="busy" :error="error" :finish-label="audit ? t('Save changes') : t('Start the audit')" @finish="finish">
        <template #site>
            <div class="grid gap-4">
                <UiField id="audit-url" :label="t('Website address')" :error="fieldError('url')">
                    <input id="audit-url" v-model="url" class="ui-input" placeholder="example.com" inputmode="url" autocomplete="url" required :aria-invalid="!!fieldError('url') || undefined">
                </UiField>
                <UiField id="audit-name" :label="t('Name')" :description="t('Optional. Shown on the report; the address is used if you leave it empty.')" :error="fieldError('name')">
                    <input id="audit-name" v-model="name" class="ui-input" maxlength="120">
                </UiField>
            </div>
        </template>
        <template #tasks>
            <div class="grid gap-4">
                <fieldset class="grid gap-2 sm:grid-cols-2">
                    <legend class="sr-only">{{ t('Tasks') }}</legend>
                    <label v-for="goal in goalChoices" :key="goal.value" class="flex cursor-pointer items-center gap-3 rounded-control border border-line p-3 text-sm font-semibold text-ink has-[:checked]:border-primary has-[:checked]:bg-primary-soft">
                        <input v-model="chosen" type="checkbox" :value="goal.value" class="h-4 w-4 accent-[var(--ui-primary)]">
                        {{ goal.label }}
                    </label>
                </fieldset>
                <UiField id="audit-custom-goal" :label="t('Your own task')" :description="t('Optional. Describe it as a visitor would, such as “Find out whether you deliver to Ireland.”')" :error="fieldError('custom_goal')">
                    <textarea id="audit-custom-goal" v-model="customGoal" class="ui-input" rows="2" maxlength="300" />
                </UiField>
                <p class="text-xs font-semibold text-muted" aria-live="polite">{{ tc(':count task chosen|:count tasks chosen', taskCount, { count: taskCount }) }}</p>
            </div>
        </template>
        <template #competitors>
            <div class="grid gap-4">
                <ul v-if="competitors.length > 0" class="grid gap-2" :aria-label="t('Competitors')">
                    <li v-for="competitor in competitors" :key="competitor.url" class="flex items-center justify-between gap-3 rounded-control border border-line p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-ink">{{ competitor.name }}</p>
                            <p class="truncate text-xs text-muted">{{ competitor.url }}</p>
                        </div>
                        <UiButton size="sm" variant="quiet" :aria-label="t('Remove :name', { name: competitor.name })" @click="competitors = competitors.filter((existing) => existing.url !== competitor.url)">{{ t('Remove') }}</UiButton>
                    </li>
                </ul>
                <div class="flex flex-wrap items-end gap-2">
                    <UiField id="audit-competitor" :label="t('Competitor’s address')" class="min-w-56 flex-1" :error="fieldError('competitors')">
                        <input id="audit-competitor" v-model="newCompetitor" class="ui-input" :disabled="atLimit" placeholder="competitor.com" inputmode="url" @keydown.enter.prevent="addTyped">
                    </UiField>
                    <UiButton :disabled="atLimit || !newCompetitor.trim()" @click="addTyped">{{ t('Add') }}</UiButton>
                    <UiButton variant="soft" :disabled="suggesting || url.trim() === ''" :aria-busy="suggesting || undefined" @click="suggest">{{ suggesting ? t('Looking…') : t('Suggest competitors') }}</UiButton>
                </div>
                <p v-if="suggestError" class="ui-error" role="alert">{{ suggestError }}</p>
                <div v-if="suggestions" class="grid gap-2" aria-live="polite">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-subtle">{{ t('Suggestions') }}</p>
                    <p v-if="suggestions.length === 0" class="text-sm text-muted">{{ t('No suggestions this time. Add competitors yourself.') }}</p>
                    <div v-for="suggestion in suggestions" :key="suggestion.url" class="flex items-start justify-between gap-3 rounded-control border border-dashed border-line p-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-ink">{{ suggestion.name }} <span class="font-normal text-muted">{{ suggestion.url }}</span></p>
                            <p class="mt-0.5 text-xs text-muted">{{ suggestion.reason }}</p>
                        </div>
                        <UiButton size="sm" :disabled="competitors.some((competitor) => competitor.url === suggestion.url) || atLimit" @click="addCompetitor({ url: suggestion.url, name: suggestion.name, source: 'suggested', reason: suggestion.reason })">
                            {{ competitors.some((competitor) => competitor.url === suggestion.url) ? t('Added') : t('Add') }}
                        </UiButton>
                    </div>
                </div>
                <p v-if="competitors.length === 0" class="text-xs text-muted">{{ t('You can skip this: the report then scores your site on its own.') }}</p>
            </div>
        </template>
        <template #review>
            <div class="grid gap-5">
                <fieldset class="grid gap-2">
                    <legend class="ui-label mb-1">{{ t('Run it again automatically') }}</legend>
                    <label v-for="option in schedules" :key="option.value" :class="['flex items-center gap-3 rounded-control border border-line p-3 text-sm font-semibold', plan.schedules.includes(option.value) ? 'cursor-pointer text-ink' : 'text-subtle']">
                        <input v-model="schedule" type="radio" name="schedule" :value="option.value" :disabled="!plan.schedules.includes(option.value)">
                        {{ option.label }}
                        <span v-if="!plan.schedules.includes(option.value) && option.plan" class="ml-auto text-xs font-bold">{{ option.plan }}</span>
                    </label>
                    <p v-if="fieldError('schedule')" class="ui-error" role="alert">{{ fieldError('schedule') }}</p>
                </fieldset>
                <dl class="grid gap-3 rounded-card bg-surface-muted p-4 text-sm sm:grid-cols-[9rem_1fr]">
                    <dt class="font-bold text-muted">{{ t('Site') }}</dt>
                    <dd class="text-ink">{{ name || url }}</dd>
                    <dt class="font-bold text-muted">{{ t('Tasks') }}</dt>
                    <dd class="text-ink">{{ [...goalChoices.filter((goal) => chosen.includes(goal.value)).map((goal) => goal.label), ...(customGoal.trim() ? [customGoal.trim()] : [])].join(' · ') }}</dd>
                    <dt class="font-bold text-muted">{{ t('Competitors') }}</dt>
                    <dd class="text-ink">{{ competitors.length ? competitors.map((competitor) => competitor.name).join(' · ') : t('None') }}</dd>
                </dl>
                <p v-if="!audit && plan.runsAllowance !== null" class="text-xs text-muted">
                    {{ tc('This uses your one audit this month.|This uses one of your :count audits this month (:used used so far).', plan.runsAllowance, { count: plan.runsAllowance, used: plan.runsUsed }) }}
                </p>
            </div>
        </template>
    </Wizard>
</template>
