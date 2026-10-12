<script setup lang="ts">
/**
 * A branch picked from a list of the repository's branches, which its Git provider sends when it can. "Another…" (or
 * no list, while it loads or when the provider can't send one) gives a text box instead, for a branch the list
 * doesn't have, a tag or a commit. `source` is the API path that lists the branches; `empty` adds a first choice that
 * sends nothing (such as "the latest main").
 */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ name: string; label: string; source: string | null; id?: string; description?: string; required?: boolean; other?: string; empty?: string }>(), {
    id: undefined, description: undefined, other: undefined, empty: undefined,
});
const model = defineModel<string>({ default: '' });
const { t } = useT();
const OTHER = '\u0000other';
const branches = ref<string[]>([]);
const loading = ref(false);
const typing = ref(false);
const controlId = computed(() => props.id ?? props.name);
const choice = computed<string | null>({
    get: () => (typing.value ? OTHER : model.value),
    set: (value) => {
        typing.value = value === OTHER;
        if (value !== OTHER) {
            model.value = value ?? '';
        }
    },
});
const options = computed(() => {
    // Keep a saved branch the list doesn't have (deleted since, or past the first 100) as a choice.
    const names = model.value !== '' && !branches.value.includes(model.value) ? [model.value, ...branches.value] : branches.value;

    return [
        ...(props.empty !== undefined ? [{ value: '', label: props.empty }] : []),
        ...names.map((name) => ({ value: name, label: name })),
        { value: OTHER, label: props.other ?? t('Another branch…') },
    ];
});

/** Load the branches from the source, if there is one. */
async function load() {
    branches.value = [];
    if (!props.source) {
        return;
    }
    loading.value = true;
    const result = await send<{ branches: string[]; error: string | null }>('GET', props.source).catch(() => null);
    branches.value = result?.branches ?? [];
    loading.value = false;
}

/** Go back from typing to the list. */
function backToList() {
    typing.value = false;
    if (model.value !== '' && !branches.value.includes(model.value) && props.empty !== undefined) {
        model.value = '';
    }
}

// A typed address changes the source on each key, so wait for a pause before asking the provider.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => props.source, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 400);
});
onBeforeUnmount(() => clearTimeout(timer));
onMounted(load);
</script>

<template>
    <div>
        <SelectField v-if="branches.length > 0 && !typing" :id="controlId" v-model="choice" v-bind="$attrs" :name="name" :label="label" :description="description" :required="required" :options="options" />
        <InputField
            v-else
            :id="controlId"
            v-model="model"
            maxlength="255"
            autocomplete="off"
            spellcheck="false"
            v-bind="$attrs"
            :name="name"
            :label="label"
            :description="loading ? t('Loading branches…') : description"
            :required="required"
            :autofocus="typing"
        />
        <button v-if="typing && branches.length > 0" type="button" class="mt-1.5 text-xs font-medium text-muted underline hover:text-ink" @click="backToList">{{ t('Choose from the list') }}</button>
    </div>
</template>
