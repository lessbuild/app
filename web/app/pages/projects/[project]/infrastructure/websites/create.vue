<script setup lang="ts">
import type { WebsiteFormOptions } from '~/types/infrastructure';
import type { ProjectOverview } from '~/types/projects';

/**
 * Create a website (the Acme theme's create website page), or with `?import=1` adopt one already on a server. The
 * setup guide sends people here with `?_return=` to come back to it.
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
    <div>
        <ProjectHeader
            :overview="data.overview"
            :title="importing ? t('Import a website') : t('Create a website')"
            :description="importing ? t('Adopt an application already in /var/www on an app server. Its files, Caddy site and database are left as they are.') : t('We set up the Caddy site, a MySQL database and user, and the .env file.')"
        />
        <AcmeEmptyState v-if="data.options.hosts.length === 0" icon="cpu" :title="t('No app servers ready')" :description="t('Websites need an active app server with MySQL. Create one first.')">
            <AcmeBtn variant="primary" icon="plus" :to="`/projects/${project.id}/infrastructure/servers/create`">{{ t('Create a server') }}</AcmeBtn>
        </AcmeEmptyState>
        <ApiForm v-else :action="`/api/app/projects/${project.id}/infrastructure/websites${importing ? '/import' : ''}`" class="grid gap-6">
            <PlanLimitAlert billing-url="/account/billing" />
            <input v-if="back" type="hidden" name="_return" :value="back">
            <AcmeCard v-if="importing" :title="t('Website')" class="max-w-2xl"><WebsiteImportFields :hosts="data.options.hosts" /></AcmeCard>
            <WebsiteFields v-else :options="data.options" />
            <div class="flex flex-wrap gap-2">
                <SubmitButton>{{ importing ? t('Import website') : t('Create website') }}</SubmitButton>
                <CancelButton :to="back ?? `/projects/${project.id}/infrastructure/websites`" />
            </div>
        </ApiForm>
    </div>
</template>
