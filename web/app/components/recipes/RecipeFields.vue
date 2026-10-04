<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A recipe's name, category, description and script; `recipe` is null for a new one. */
const props = defineProps<{ recipe: { name: string; category: string; description: string | null; script: string } | null; categories: Option[] }>();
const { t } = useT();
const category = ref<string | null>(props.recipe?.category ?? 'utilities');
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <InputField name="name" :label="t('Name')" :model-value="recipe?.name" maxlength="120" required />
        <SelectField v-model="category" name="category" :label="t('Category')" :options="categories" />
        <div class="sm:col-span-2"><InputField name="description" :label="t('What it does (optional)')" :model-value="recipe?.description" maxlength="1000" /></div>
        <div class="sm:col-span-2">
            <TextareaField
                name="script"
                :label="t('Bash script')"
                rows="14"
                class="font-mono text-xs"
                :model-value="recipe?.script"
                :description="t('Runs as root at the end of a new server’s provisioning. Make it safe to run once on a fresh Ubuntu server.')"
                spellcheck="false"
                required
            />
        </div>
    </div>
</template>
