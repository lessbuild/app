<script setup lang="ts">
import type { WebsiteFormOptions } from '~/types/infrastructure';
import type { ProjectOverview } from '~/types/projects';

/**
 * Create a website (or, with `?import=1`, adopt one already on a server), as a page of its own. The setup guide sends
 * people here with `?_return=` to come back to it.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; options: WebsiteFormOptions }>(() => `/projects/${route.params.project}/infrastructure/websites/create`);
const importing = computed(() => route.query.import === '1');
const project = computed(() => data.value.overview.project);
const back = computed(() => (typeof route.query._return === 'string' ? route.query._return : null));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="importing ? t('Import a website') : t('Create a website')"
            :description="importing ? t('Adopt an application already in /var/www on an app server. Its files, Caddy site and database are left as they are.') : t('We set up the Caddy site, a MySQL database and user, and the .env file.')"
        />
        <EmptyState v-if="data.options.hosts.length === 0" icon="server" :title="t('No app servers ready')" :description="t('Websites need an active app server with MySQL. Create one first.')">
            <template #action><UiButton :to="{ query: { ...route.query, dialog: 'create-server' } }">{{ t('Create a server') }}</UiButton></template>
        </EmptyState>
        <section v-else class="ui-card">
            <ApiForm :action="`/api/app/projects/${project.id}/infrastructure/websites${importing ? '/import' : ''}`" class="grid gap-5 p-4 sm:p-6">
                <PlanLimitAlert billing-url="/account/billing" />
                <input v-if="back" type="hidden" name="_return" :value="back">
                <WebsiteImportFields v-if="importing" :hosts="data.options.hosts" />
                <WebsiteFields v-else :options="data.options" />
                <div class="flex flex-wrap gap-3">
                    <SubmitButton>{{ importing ? t('Import website') : t('Create website') }}</SubmitButton>
                    <UiButton :to="back ?? `/projects/${project.id}/infrastructure/websites`" variant="quiet">{{ t('Cancel') }}</UiButton>
                </div>
            </ApiForm>
        </section>
        <CreateServerDialog v-if="data.options.hosts.length === 0" :project-id="project.id" />
    </div>
</template>
