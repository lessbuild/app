<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option, Tone } from '~/types/ui';

/** One status page: its components and their state, the updates posted on it, sharing and its custom domain. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type StatusUpdate = {
    id: number;
    title: string;
    kind: string;
    status: string;
    statusLabel: string;
    closed: boolean;
    severity: string;
    message: string;
    startsAt: string;
    endsAt: string | null;
    rootCause: string | null;
    remediation: string | null;
    followUp: string | null;
};
type StatusPagePage = {
    overview: ProjectOverview;
    page: {
        id: number;
        name: string;
        slug: string;
        published: boolean;
        url: string;
        badgeUrl: string;
        uptimeBadgeUrl: string;
        embedUrl: string;
        customDomain: string | null;
        domainVerified: boolean;
        domainRecord: { name: string; value: string } | null;
        domainTarget: string;
    };
    overall: string;
    overallLabel: string;
    components: Array<{ name: string; type: string; uptime: number | null; state: string; stateLabel: string }>;
    subscribers: number;
    updates: StatusUpdate[];
    statuses: Record<string, Option[]>;
    canManage: boolean;
};
const { t, tc, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<StatusPagePage>(() => `/projects/${route.params.project}/monitoring/status-pages/${route.params.page}`);
const project = computed(() => data.value.overview.project);
const page = computed(() => data.value.page);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/status-pages/${page.value.id}`);
const stateTone = (state: string): Tone => ({ operational: 'success', degraded: 'warning', partial_outage: 'warning', major_outage: 'danger', maintenance: 'info' } as Record<string, Tone>)[state] ?? 'neutral';
const severityLabels = computed<Record<string, string>>(() => ({ minor: t('Minor'), major: t('Major'), critical: t('Critical') }));
const badge = computed(() => `[![${t(':page status badge', { page: page.value.name })}](${page.value.badgeUrl})](${page.value.url})`);
const uptimeBadge = computed(() => `[![${t(':page uptime badge', { page: page.value.name })}](${page.value.uptimeBadgeUrl})](${page.value.url})`);
const widget = computed(() => `<iframe src='${page.value.embedUrl}' title='${page.value.name.replace(/'/g, '&#39;')} status' width='260' height='40' style='border:0' loading='lazy'></iframe>`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="page.name" :description="tc(':count confirmed subscriber|:count confirmed subscribers', data.subscribers)">
            <template #actions>
                <a :href="page.url" target="_blank" rel="noopener" class="ui-btn ui-btn-secondary ui-btn-sm"><Icon name="external-link" class="h-4 w-4" />{{ t('Open public page') }}</a>
                <template v-if="data.canManage">
                    <AcmeBtn :to="`/projects/${project.id}/monitoring/status-pages/${page.id}/edit`" size="sm">{{ t('Edit') }}</AcmeBtn>
                    <DeleteDialog id="delete-status-page" :title="t('Delete :page?', { page: page.name })" :description="t('The public page goes away and its subscribers stop getting updates.')" :action="base" :submit-label="t('Delete status page')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Delete') }}</AcmeBtn></template>
                    </DeleteDialog>
                </template>
            </template>
        </ProjectHeader>

        <AcmeAlert v-if="!page.published" tone="info">{{ t('Draft: only your team can see this page.') }}</AcmeAlert>
        <section class="ui-card overflow-hidden" aria-labelledby="components-heading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <h2 id="components-heading" class="text-sm font-bold text-ink">{{ t('Components') }}</h2>
                <AcmeBadge :tone="acmeTone(stateTone(data.overall))">{{ data.overallLabel }}</AcmeBadge>
            </div>
            <p v-if="data.components.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('No components yet. Edit the page to choose monitors.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="(component, index) in data.components" :key="index" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <span class="font-bold text-ink">{{ component.name }}</span>
                    <span class="flex items-center gap-3">
                        <span v-if="component.uptime !== null" class="text-xs tabular-nums text-muted">{{ t(':uptime% over 30 days', { uptime: component.uptime }) }}</span>
                        <AcmeBadge :tone="acmeTone(stateTone(component.state))">{{ component.stateLabel }}</AcmeBadge>
                    </span>
                </li>
            </ul>
        </section>

        <section class="ui-card overflow-hidden" aria-labelledby="updates-heading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <h2 id="updates-heading" class="text-sm font-bold text-ink">{{ t('Updates') }}</h2>
                <FormDialog
                    v-if="data.canManage"
                    id="post-update"
                    :title="t('Post an update')"
                    :description="page.published ? t('Shown on the page at once and emailed to confirmed subscribers.') : t('Saved now; shown and emailed once the page is published.')"
                    :action="`${base}/updates`"
                    :submit="t('Post update')"
                    size="wide"
                >
                    <template #trigger="{ open }"><AcmeBtn variant="primary" size="sm" icon="plus" @click="open">{{ t('Post an update') }}</AcmeBtn></template>
                    <StatusUpdateFields :update="null" :statuses="data.statuses" />
                </FormDialog>
            </div>
            <p v-if="data.updates.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('Nothing posted yet.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="update in data.updates" :key="update.id" class="grid gap-1 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                            {{ update.title }}
                            <AcmeBadge :tone="acmeTone(update.closed ? 'success' : update.kind === 'maintenance' ? 'info' : 'warning')">{{ update.statusLabel }}</AcmeBadge>
                        </p>
                        <FormDialog v-if="data.canManage" :id="`edit-update-${update.id}`" :title="t('Edit')" :action="`${base}/updates/${update.id}`" method="PUT" :submit="t('Save and notify')" size="wide">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Change') }}</AcmeBtn></template>
                            <StatusUpdateFields :update="update" :statuses="data.statuses" />
                        </FormDialog>
                    </div>
                    <p class="text-xs text-muted">
                        {{ update.kind === 'maintenance' ? t('Maintenance') : t('Incident · :impact impact', { impact: severityLabels[update.severity] ?? update.severity }) }}
                        · {{ dateTime(update.startsAt) }}<template v-if="update.endsAt"> – {{ dateTime(update.endsAt) }}</template>
                    </p>
                    <p class="whitespace-pre-line text-sm">{{ update.message }}</p>
                </li>
            </ul>
        </section>

        <AcmeCard v-if="page.published" :padded="false" :title="t('Share and embed')" :description="t('Put the page’s state in a README, a footer or your app. Badges and the widget update within a minute.')">
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <div class="flex flex-wrap items-center gap-3">
                    <img :src="page.badgeUrl" :alt="t(':page status badge', { page: page.name })" height="20">
                    <img :src="page.uptimeBadgeUrl" :alt="t(':page uptime badge', { page: page.name })" height="20">
                </div>
                <div class="grid gap-1"><p class="text-xs font-bold text-muted">{{ t('Status badge (Markdown)') }}</p><CodeBlock :code="badge" class="whitespace-pre-wrap break-all" /></div>
                <div class="grid gap-1"><p class="text-xs font-bold text-muted">{{ t('Uptime badge (Markdown)') }}</p><CodeBlock :code="uptimeBadge" class="whitespace-pre-wrap break-all" /></div>
                <div class="grid gap-1"><p class="text-xs font-bold text-muted">{{ t('Widget for your site (HTML)') }}</p><CodeBlock :code="widget" class="whitespace-pre-wrap break-all" /></div>
            </div>
        </AcmeCard>

        <AcmeCard v-if="data.canManage" :padded="false" :title="t('Custom domain')" :description="t('Serve this page on a domain of your own, such as status.example.com. HTTPS is set up for you.')">
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <ApiForm :action="`${base}/domain`" method="PUT" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-64 flex-1"><InputField id="custom-domain" name="custom_domain" :label="t('Domain')" :model-value="page.customDomain" placeholder="status.example.com" maxlength="253" /></div>
                    <SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton>
                </ApiForm>
                <template v-if="page.customDomain && page.domainRecord">
                    <p v-if="page.domainVerified" class="flex flex-wrap items-center gap-2 text-sm">
                        <AcmeBadge tone="green">{{ t('Verified') }}</AcmeBadge>
                        <Rich :text="t('Served at :address')"><template #address><a :href="`https://${page.customDomain}`" class="font-bold text-primary hover:underline" target="_blank" rel="noopener">{{ page.customDomain }}</a></template></Rich>
                        <span v-if="!page.published" class="text-muted">{{ t('Publish the page to serve it there.') }}</span>
                    </p>
                    <template v-else>
                        <p class="text-sm text-muted">{{ t('Add these two DNS records at your DNS provider, then check them. The first proves the domain is yours; the second sends its visitors here.') }}</p>
                        <DataTable :caption="t('DNS records')">
                            <template #head>
                                <tr><th scope="col">{{ t('Type') }}</th><th scope="col">{{ t('Name') }}</th><th scope="col">{{ t('Value') }}</th></tr>
                            </template>
                            <tr><td>TXT</td><td class="break-all font-mono text-xs">{{ page.domainRecord.name }}</td><td class="break-all font-mono text-xs">{{ page.domainRecord.value }}</td></tr>
                            <tr><td>CNAME</td><td class="break-all font-mono text-xs">{{ page.customDomain }}</td><td class="break-all font-mono text-xs">{{ page.domainTarget }}</td></tr>
                        </DataTable>
                        <p class="text-xs text-muted">{{ t('For a bare domain (example.com) that can’t have a CNAME, point an A record at the same address as :target.', { target: page.domainTarget }) }}</p>
                        <ApiForm :action="`${base}/domain/verify`"><SubmitButton variant="secondary" size="sm">{{ t('Check DNS') }}</SubmitButton></ApiForm>
                    </template>
                </template>
            </div>
        </AcmeCard>
    </div>
</template>
