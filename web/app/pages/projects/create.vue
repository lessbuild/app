<script setup lang="ts">
import type { ServiceOption } from '~/types/projects';

/**
 * A new project (the Acme theme's new-project page): its name and description, and which services to start with, each
 * explained. `?services=analytics` starts with that service chosen, for links such as "Create a project" on a service.
 */
definePageMeta({ layout: 'app' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ services: ServiceOption[] }>('/projects/new');
const asked = [route.query.services].flat().filter((key): key is string => typeof key === 'string');
const chosen = ref<string[]>(asked.length > 0 ? asked.filter((key) => data.value.services.some((service) => service.key === key)) : ['deploy', 'infrastructure', 'monitoring'].filter((key) => data.value.services.some((service) => service.key === key)));
const next = computed(() => [t('A Production environment is created'), t('The setup guide walks you through each service'), t('Invite teammates whenever you’re ready')]);

/**
 * Choose a service, or take it off the list.
 *
 * @param key The service.
 */
function toggle(key: string) {
    chosen.value = chosen.value.includes(key) ? chosen.value.filter((item) => item !== key) : [...chosen.value, key];
}
</script>

<template>
    <div>
        <PlatformHeader :title="t('New project')" :subtitle="t('One project per app or site. It starts with a Production environment; you can add staging and others later.')" />
        <ApiForm action="/api/app/projects" class="!grid gap-6 @3xl:grid-cols-[1fr_20rem]">
            <AcmeCard>
                <div class="grid gap-4">
                    <InputField id="project-name" name="name" :label="t('Project name')" maxlength="100" autocomplete="off" required autofocus />
                    <TextareaField id="project-description" name="description" :label="t('Description')" :description="t('Optional. What this project is, for your teammates.')" maxlength="500" rows="3" />
                </div>
                <fieldset class="mt-6">
                    <legend class="text-sm font-medium text-ink">{{ t('What do you need?') }}</legend>
                    <p class="mt-1 text-sm text-muted">{{ t('Use one service or several. Analytics and Monitoring work with any site, wherever it’s hosted, with no server here. You can change this later.') }}</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label v-for="service in data.services" :key="service.key" :class="['flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition', chosen.includes(service.key) ? 'border-accent bg-accent/[.05] ring-1 ring-accent' : 'border-line hover:bg-black/[.02] dark:hover:bg-white/[.03]']">
                            <input type="checkbox" name="services[]" :value="service.key" class="mt-1 size-4 accent-[var(--ui-primary)]" :checked="chosen.includes(service.key)" @change="toggle(service.key)">
                            <span :class="['grid size-8 shrink-0 place-items-center rounded-lg text-white', serviceStyle(service.key).tone]" aria-hidden="true"><AcmeIcon :name="serviceStyle(service.key).icon" :size="15" /></span>
                            <span><span class="block text-sm font-medium text-ink">{{ service.name }}</span><span class="text-xs text-muted">{{ service.tagline }}</span></span>
                        </label>
                    </div>
                </fieldset>
            </AcmeCard>
            <aside class="space-y-4">
                <AcmeCard :title="t('What happens next')">
                    <ol class="space-y-3 text-sm text-ink">
                        <li v-for="(line, index) in next" :key="line" class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-black/[.05] text-xs font-semibold dark:bg-white/10">{{ index + 1 }}</span>{{ line }}</li>
                    </ol>
                </AcmeCard>
                <div class="flex gap-2"><SubmitButton class="flex-1 justify-center">{{ t('Create project') }}</SubmitButton><CancelButton to="/dashboard" /></div>
                <p class="text-center text-sm text-muted">{{ t('Prefer a head start?') }} <NuxtLink to="/projects/templates" class="font-medium text-ink underline underline-offset-2">{{ t('Use a template') }}</NuxtLink></p>
            </aside>
        </ApiForm>
    </div>
</template>
