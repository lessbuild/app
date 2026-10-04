<script setup lang="ts">
/** A goal's fields: its name, what counts (a page or a custom event), how it matches, and whether it counts now. */
type Goal = { name: string; kind: string; matchType: string; matchValue: string; active: boolean };
const props = defineProps<{ id: string; goal?: Goal | null }>();
const { t } = useT();
const kind = ref(props.goal?.kind ?? 'path');
const kinds = computed(() => [{ value: 'path', label: t('Visiting a page') }, { value: 'event', label: t('A custom event') }]);
const matches = computed(() => [{ value: 'exact', label: t('Exactly') }, { value: 'prefix', label: t('Starts with') }]);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><InputField :id="`${id}-name`" name="name" :label="t('Goal name')" :model-value="goal?.name ?? ''" :placeholder="t('Demo requested')" maxlength="120" required autofocus /></div>
        <SelectField :id="`${id}-kind`" v-model="kind" name="kind" :label="t('What counts')" :options="kinds" required />
        <SelectField :id="`${id}-match`" name="match_type" :label="t('Match')" :options="matches" :model-value="goal?.matchType ?? 'exact'" required />
        <div class="sm:col-span-2">
            <InputField :id="`${id}-value`" name="match_value" :label="kind === 'event' ? t('Event name') : t('Page')" :model-value="goal?.matchValue ?? ''" :placeholder="kind === 'event' ? 'signup' : '/thank-you'" maxlength="255" required />
        </div>
        <div class="sm:col-span-2"><CheckboxField :id="`${id}-active`" name="active" unchecked-value="0" :label="t('Count this goal in reports')" :checked="goal?.active ?? true" /></div>
    </div>
</template>
