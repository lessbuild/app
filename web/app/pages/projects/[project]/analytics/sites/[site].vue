<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * A site's setup and settings: the tracking snippet, verification, Search Console, raw export, Google Analytics
 * imports, view-only access, reports and alerts, sharing, its settings and deleting it. Each change opens a dialog.
 */
definePageMeta({ layout: 'app', service: 'analytics' });
type SitePage = {
    overview: ProjectOverview;
    site: {
        id: number; name: string; publicId: string; domains: string[]; timezone: string; excludedPaths: string[]; excludedIps: string[]; customProperties: string[];
        contentGroups: Array<{ name: string; pattern: string }>; blockedReferrers: string[]; verified: boolean; verifiedAt: string | null; collecting: boolean; collectionEnabled: boolean;
    };
    trackerUrl: string;
    origin: string;
    filtered: Array<{ label: string; count: number }>;
    searchConsole: { configured: boolean; connected: boolean; property: string | null; properties: string[]; error: string | null };
    rawExport: { bucketId: number | null; prefix: string | null; exportedUntil: string | null; error: string | null; path: string; buckets: Option[] };
    googleAnalytics: {
        configured: boolean; connectedId: number | null; properties: Array<{ id: string; name: string }>; error: string | null;
        imports: Array<{ id: number; property: string | null; from: string | null; until: string | null; status: string; days: number; error: string | null }>;
        defaultFrom: string; defaultUntil: string;
    };
    viewers: Array<{ id: number; email: string; lastViewedAt: string | null }>;
    notifications: Array<{ id: number; kind: string; destination: string; threshold: number | null; view: string | null; lastSentAt: string | null; error: string | null }>;
    notificationKinds: Option[];
    notificationChannels: Option[];
    savedViews: Option[];
    sharing: { url: string | null; embedUrl: string | null; password: boolean; sharedAt: string | null } | null;
    timezones: string[];
    canManage: boolean;
};
const { t, tc, number, date } = useT();
const route = useRoute();
const { data } = await useApi<SitePage>(() => `/projects/${route.params.project}/analytics/sites/${route.params.site}`);
const project = computed(() => data.value.overview.project);
const site = computed(() => data.value.site);
const base = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${site.value.id}`);
const snippet = computed(() => `<script defer data-site="${site.value.publicId}" src="${data.value.trackerUrl}"></` + 'script>');
const fullSnippet = computed(() => snippet.value.replace(' src=', ' data-outbound data-downloads data-vitals src='));
const proxySnippet = computed(() => `<script defer data-site="${site.value.publicId}" data-api="/bp/event" src="/bp/js/v1.js"></` + 'script>');
const host = computed(() => new URL(data.value.origin).host);
const caddyProxy = computed(() => `handle_path /bp/js/* {\n    rewrite * /tracker{path}\n    reverse_proxy ${data.value.origin} {\n        header_up Host ${host.value}\n    }\n}\nhandle_path /bp/event/* {\n    rewrite * /api/v1/collect{path}\n    reverse_proxy ${data.value.origin} {\n        header_up Host ${host.value}\n    }\n}`);
const nginxProxy = computed(() => `location /bp/js/ {\n    proxy_pass ${data.value.origin}/tracker/;\n    proxy_set_header Host ${host.value};\n    proxy_ssl_server_name on;\n}\nlocation /bp/event/ {\n    proxy_pass ${data.value.origin}/api/v1/collect/;\n    proxy_set_header Host ${host.value};\n    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n    proxy_ssl_server_name on;\n}`);
const embedCode = computed(() => (data.value.sharing?.embedUrl ? `<iframe src="${data.value.sharing.embedUrl}" style="width: 100%; height: 1600px; border: 0" loading="lazy" title="${site.value.name} analytics"></iframe>` : null));
const options = computed(() => [
    { attribute: 'data-outbound', text: t('clicks on links to other sites') },
    { attribute: 'data-downloads', text: t('file downloads (PDFs, zips, documents and more), or list your own: data-downloads="pdf,zip"') },
    { attribute: 'data-vitals', text: t('page speed (Core Web Vitals) as real visitors experience it') },
    { attribute: 'data-not-found', text: t('on your 404 page only, to see which missing pages people reach') },
    { attribute: 'data-clicks', text: t('click maps: where people click on each page') },
    { attribute: 'data-forms', text: t('form analytics: which fields people reach and leave on (never what they type)') },
    { attribute: 'data-hash', text: t('count #/pages as separate pages, for sites that route with the URL’s hash') },
    { attribute: 'data-retention', text: t('recognise returning browsers for retention reports (keeps a random ID in the browser)') },
]);
const importStatus = (status: string, days: number) => ({ queued: t('Waiting'), running: t('Importing'), failed: t('Failed') } as Record<string, string>)[status] ?? tc(':count day imported|:count days imported', days, { count: number(days) });
const bucketOptions = computed(() => [{ value: '', label: t('Don’t export') }, ...data.value.rawExport.buckets]);
const propertyOptions = computed(() => data.value.googleAnalytics.properties.map((property) => ({ value: property.id, label: property.name })));
const consoleOptions = computed(() => data.value.searchConsole.properties.map((property) => ({ value: property, label: property })));
const viewOptions = computed(() => [{ value: '', label: t('All traffic') }, ...data.value.savedViews]);
const notice = computed(() => (typeof route.query.error === 'string' ? { tone: 'danger' as const, text: route.query.error } : typeof route.query.notice === 'string' ? { tone: 'success' as const, text: route.query.notice } : null));
const tabs = computed<Record<string, string>>(() => ({
    snippet: t('Snippet'),
    proxy: t('Own domain'),
    ...(data.value.canManage ? { data: t('Imports and export'), sharing: t('Sharing and alerts'), settings: t('Settings') } : {}),
}));
const tab = computed(() => (typeof route.query.tab === 'string' && route.query.tab in tabs.value ? route.query.tab : 'snippet'));
/** Off to Google to connect: the API answers with the address to go to. */
const away = (result: Record<string, unknown>) => (typeof result.redirect === 'string' ? result.redirect : null);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="site.name" :description="site.domains.join(' · ')">
            <template #actions>
                <ApiForm v-if="data.canManage" :action="`${base}/collection`" method="PUT">
                    <input type="hidden" name="enabled" :value="site.collectionEnabled ? 0 : 1">
                    <SubmitButton variant="secondary">{{ site.collectionEnabled ? t('Pause collecting') : t('Resume collecting') }}</SubmitButton>
                </ApiForm>
                <AcmeBtn variant="primary" icon="pie" :to="`/projects/${project.id}/analytics?site=${site.id}`">{{ t('View report') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
        <AcmeAlert v-if="!site.verified" tone="warning" :title="t('Waiting for verification')">
            {{ t('Data is only accepted once one of the site’s hostnames is a verified domain of :project.', { project: project.name }) }}
            <NuxtLink :to="`/projects/${project.id}/domains`" class="font-medium underline">{{ t('Project domains') }}</NuxtLink>
        </AcmeAlert>
        <PageTabs :tabs="tabs" :current="tab" :label="t('Site sections')" />
        <AcmeAlert v-if="notice" :tone="notice.tone" :role="notice.tone === 'danger' ? 'alert' : 'status'">{{ notice.text }}</AcmeAlert>

        <AcmeCard v-if="tab === 'snippet'" :padded="false" :title="t('Tracking snippet')" :description="t('Paste it into the <head> of every page. It sets no cookies and respects an optional consent callback (data-consent).')">
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <CodeBlock :code="snippet" class="whitespace-pre-wrap break-all" />
                <p class="text-xs text-muted">{{ t('Custom events: window.buildpusher.track(\'signup\'). Add revenue to measure what goals and campaigns earn: window.buildpusher.track(\'purchase\', {revenue: 49.99, currency: \'EUR\'}).') }}</p>
                <p class="text-xs text-muted">{{ t('A/B tests: var headline = window.buildpusher.variant(\'headline\', [\'control\', \'bold\']) returns the variant to show; set up the experiment in Explore → Experiments.') }}</p>
                <p class="text-xs text-muted">{{ t('Without JavaScript: give a button or link the class bp-event-name=Signup (and bp-event-plan=pro for a property); clicking it sends the event.') }}</p>
                <Disclosure :title="t('Optional: count more automatically')">
                    <div class="grid gap-3 text-sm">
                        <ul class="grid gap-1.5 text-muted">
                            <li v-for="option in options" :key="option.attribute"><code class="font-mono text-ink">{{ option.attribute }}</code> · {{ option.text }}</li>
                        </ul>
                        <CodeBlock :code="fullSnippet" class="whitespace-pre-wrap break-all" />
                    </div>
                </Disclosure>
                <p class="text-sm text-muted">{{ t('To leave your own visits out, open any page of the site once with ?bp_ignore=1 in each browser you use (?bp_ignore=0 counts it again).') }}</p>
                <p v-if="data.filtered.length > 0" class="text-sm text-muted">{{ t('Left out in the last 30 days:') }} {{ data.filtered.map((row) => `${row.label} ${number(row.count)}`).join(' · ') }}</p>
            </div>
        </AcmeCard>

        <AcmeCard v-if="tab === 'snippet'" :padded="false" :title="t('Verification')" :description="t('Data is only accepted once one of the site’s hostnames is a verified domain of :project.', { project: project.name })">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <AcmeBadge v-if="site.verified" tone="green"><Rich :text="t('Verified :time')"><template #time><RelativeTime v-if="site.verifiedAt" :at="site.verifiedAt" /></template></Rich></AcmeBadge>
                <AcmeBadge v-else tone="amber">{{ t('Not verified') }}</AcmeBadge>
                <div v-if="!site.verified && data.canManage" class="flex flex-wrap gap-2">
                    <AcmeBtn variant="ghost" size="sm" :to="`/projects/${project.id}/domains`">{{ t('Project domains') }}</AcmeBtn>
                    <ApiForm :action="`${base}/verify`"><SubmitButton variant="secondary" size="sm">{{ t('Check again') }}</SubmitButton></ApiForm>
                </div>
            </div>
        </AcmeCard>

        <AcmeCard v-if="tab === 'proxy'" :padded="false" :title="t('Send through your own domain')" :description="t('Optional. Serve the script and send events from the site’s own domain, so ad blockers that stop third-party analytics don’t stop it. Add the proxy rules to your web server, then use this snippet instead.')">
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <CodeBlock :code="proxySnippet" class="whitespace-pre-wrap break-all" />
                <Disclosure :title="t('Caddy (for a BuildPusher website, paste it into the website’s Caddy directives)')"><CodeBlock :code="caddyProxy" class="whitespace-pre-wrap break-all" /></Disclosure>
                <Disclosure :title="t('Nginx')"><CodeBlock :code="nginxProxy" class="whitespace-pre-wrap break-all" /></Disclosure>
                <p class="text-xs text-muted">{{ t('The proxy must pass the visitor’s address in X-Forwarded-For (Caddy does this itself), or every visitor looks like your server.') }}</p>
            </div>
        </AcmeCard>

        <template v-if="data.canManage">
            <AcmeCard v-if="tab === 'data'" :padded="false" :title="t('Google Search Console')" :description="t('See which Google searches bring people to the site, next to its visits. Read-only access; Google’s figures lag by about two days.')">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <p v-if="!data.searchConsole.configured && !data.searchConsole.connected" class="text-sm text-muted">{{ t('Search Console isn’t set up on this platform yet: it needs a Google OAuth client (GOOGLE_SEARCH_CONSOLE_CLIENT_ID and GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET).') }}</p>
                    <ApiForm v-else-if="!data.searchConsole.connected" :action="`${base}/search-console/connect`" :after="away">
                        <SubmitButton>{{ t('Connect Google Search Console') }}</SubmitButton>
                    </ApiForm>
                    <template v-else>
                        <AcmeAlert v-if="data.searchConsole.error" tone="warning">{{ data.searchConsole.error }}</AcmeAlert>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm"><span class="text-muted">{{ t('Property') }}:</span> <span class="font-bold text-ink">{{ data.searchConsole.property ?? t('Choose the property for this site.') }}</span></p>
                            <div class="flex gap-1">
                                <FormDialog id="search-console-property" :title="t('Search Console property')" :action="`${base}/search-console`" method="PUT" :submit="t('Use this property')">
                                    <template #trigger="{ open }"><AcmeBtn variant="secondary" size="sm" @click="open">{{ t('Change') }}</AcmeBtn></template>
                                    <SelectField id="search-console-property-value" name="search_console_property" :label="t('Property')" :options="consoleOptions" :model-value="data.searchConsole.property ?? ''" :placeholder="t('Choose…')" required />
                                </FormDialog>
                                <DeleteDialog id="search-console-disconnect" :title="t('Disconnect Search Console?')" :description="t('Search terms stop showing in the report.')" :action="`${base}/search-console`" :submit-label="t('Disconnect')">
                                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Disconnect') }}</AcmeBtn></template>
                                </DeleteDialog>
                            </div>
                        </div>
                    </template>
                </div>
            </AcmeCard>

            <AcmeCard v-if="tab === 'data'" :padded="false" :title="t('Raw data export')" :description="t('Each night, write the previous day’s raw events to a storage bucket as gzipped JSON lines, one file per day, laid out for BigQuery, Athena or your own tools. Use a Google Cloud Storage bucket to load into BigQuery directly.')">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <AcmeAlert v-if="data.rawExport.error" tone="danger">{{ data.rawExport.error }}</AcmeAlert>
                    <p v-if="data.rawExport.bucketId" class="text-sm text-muted">
                        {{ data.rawExport.exportedUntil ? t('Exported up to :date.', { date: date(data.rawExport.exportedUntil) }) : t('The first file is written tonight.') }}
                        <code class="font-mono text-ink">{{ data.rawExport.path }}</code>
                    </p>
                    <p v-if="data.rawExport.buckets.length === 0" class="text-sm text-muted">
                        {{ t('Add a storage bucket to this project first.') }} <NuxtLink :to="`/projects/${project.id}/infrastructure/storage`" class="ui-link">{{ t('Storage') }}</NuxtLink>
                    </p>
                    <FormDialog v-else id="raw-export" :title="t('Raw data export')" :action="`${base}/raw-export`" method="PUT" :submit="t('Save')">
                        <template #trigger="{ open }"><div><AcmeBtn variant="secondary" size="sm" @click="open">{{ data.rawExport.bucketId ? t('Change') : t('Set up export') }}</AcmeBtn></div></template>
                        <SelectField id="raw-export-bucket" name="export_bucket_id" :label="t('Bucket')" :options="bucketOptions" :model-value="data.rawExport.bucketId ? String(data.rawExport.bucketId) : ''" />
                        <InputField id="raw-export-prefix" name="export_prefix" :label="t('Folder (optional)')" :model-value="data.rawExport.prefix ?? ''" placeholder="analytics" maxlength="200" />
                    </FormDialog>
                </div>
            </AcmeCard>

            <AcmeCard v-if="tab === 'data'" :padded="false" :title="t('Import from Google Analytics')" :description="t('Bring a GA4 property’s daily history (pages, sources, channels, countries, cities, devices, browsers and campaigns) into this site’s reports. Only days before this site’s own data are imported, so nothing is counted twice.')">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <ul v-if="data.googleAnalytics.imports.length > 0" class="divide-y divide-line">
                        <li v-for="item in data.googleAnalytics.imports" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                <span class="font-bold text-ink">{{ item.property }}</span>
                                <span class="text-muted"> · {{ item.from }} – {{ item.until }} · {{ importStatus(item.status, item.days) }}</span>
                                <span v-if="item.error" class="block text-xs text-danger">{{ item.error }}</span>
                            </span>
                            <DeleteDialog :id="`import-${item.id}`" :title="t('Remove this import?')" :description="t('The imported history is deleted from the reports.')" :action="`${base}/imports/${item.id}`" :submit-label="t('Remove import')">
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove import') }}</AcmeBtn></template>
                            </DeleteDialog>
                        </li>
                    </ul>
                    <p v-if="!data.googleAnalytics.configured" class="text-sm text-muted">{{ t('Google sign-in isn’t set up on this platform yet.') }}</p>
                    <template v-else-if="data.googleAnalytics.connectedId">
                        <AcmeAlert v-if="data.googleAnalytics.error" tone="warning">{{ data.googleAnalytics.error }}</AcmeAlert>
                        <FormDialog id="ga-import" :title="t('Import from Google Analytics')" :description="t('Only days before this site’s own data are imported.')" :action="`${base}/imports/${data.googleAnalytics.connectedId}`" :submit="t('Import')">
                            <template #trigger="{ open }"><div><AcmeBtn variant="primary" size="sm" @click="open">{{ t('Choose what to import') }}</AcmeBtn></div></template>
                            <SelectField id="ga-property" name="property" :label="t('Property')" :options="propertyOptions" required />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <InputField id="ga-from" name="from" type="date" :label="t('From')" :model-value="data.googleAnalytics.defaultFrom" required />
                                <InputField id="ga-until" name="until" type="date" :label="t('To')" :model-value="data.googleAnalytics.defaultUntil" required />
                            </div>
                        </FormDialog>
                    </template>
                    <ApiForm v-else :action="`${base}/imports/google`" :after="away">
                        <SubmitButton variant="secondary">{{ t('Connect Google Analytics') }}</SubmitButton>
                    </ApiForm>
                </div>
            </AcmeCard>

            <AcmeCard v-if="tab === 'sharing'" :padded="false" :title="t('View-only access')" :description="t('Let someone see this site’s report without joining the account, such as a client. Each person gets their own link, and you can take it away on its own.')">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <ul v-if="data.viewers.length > 0" class="divide-y divide-line">
                        <li v-for="viewer in data.viewers" :key="viewer.id" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                <span class="font-bold text-ink">{{ viewer.email }}</span>
                                <span class="text-muted"> · <Rich v-if="viewer.lastViewedAt" :text="t('last viewed :time')"><template #time><RelativeTime :at="viewer.lastViewedAt" /></template></Rich><template v-else>{{ t('not viewed yet') }}</template></span>
                            </span>
                            <DeleteDialog :id="`viewer-${viewer.id}`" :title="t('Remove :email?', { email: viewer.email })" :description="t('Their link stops working.')" :action="`${base}/viewers/${viewer.id}`" :submit-label="t('Remove')">
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                            </DeleteDialog>
                        </li>
                    </ul>
                    <FormDialog id="invite-viewer" :title="t('Give view-only access')" :description="t('They get an email with their own link to the report.')" :action="`${base}/viewers`" :submit="t('Invite')">
                        <template #trigger="{ open }"><div><AcmeBtn variant="secondary" size="sm" icon="plus" @click="open">{{ t('Invite') }}</AcmeBtn></div></template>
                        <InputField id="viewer-email" name="email" type="email" :label="t('Email')" required autofocus />
                    </FormDialog>
                </div>
            </AcmeCard>

            <AcmeCard v-if="tab === 'sharing'" :padded="false" :title="t('Reports and alerts')" :description="t('Email or Slack a summary every week (Mondays) or month (the 1st), from 8am in the site’s time zone, or email a CSV of a saved view. Alerts tell you when a lot of people are on the site at once, or when yesterday’s visitors or conversions were far from normal for that day of the week.')">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <ul v-if="data.notifications.length > 0" class="divide-y divide-line">
                        <li v-for="item in data.notifications" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                <span class="font-bold text-ink">{{ item.kind }}</span>
                                <span class="text-muted">
                                    · {{ item.destination }}<template v-if="item.threshold"> · {{ tc('at :count visitor|at :count visitors', item.threshold, { count: number(item.threshold) }) }}</template><template v-if="item.view"> · {{ item.view }}</template>
                                    <template v-if="item.lastSentAt"> · <Rich :text="t('last sent :time')"><template #time><RelativeTime :at="item.lastSentAt" /></template></Rich></template>
                                </span>
                                <span v-if="item.error" class="block text-xs text-danger">{{ item.error }}</span>
                            </span>
                            <DeleteDialog :id="`notification-${item.id}`" :title="t('Remove this report or alert?')" :action="`${base}/notifications/${item.id}`" :submit-label="t('Remove')">
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                            </DeleteDialog>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted">{{ t('No reports or alerts yet.') }}</p>
                    <FormDialog id="add-notification" :title="t('Add a report or alert')" :action="`${base}/notifications`" :submit="t('Add')">
                        <template #trigger="{ open }"><div><AcmeBtn variant="secondary" size="sm" icon="plus" @click="open">{{ t('Add a report or alert') }}</AcmeBtn></div></template>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <SelectField id="notification-kind" name="kind" :label="t('What')" :options="data.notificationKinds" />
                            <SelectField id="notification-channel" name="channel" :label="t('How')" :options="data.notificationChannels" />
                        </div>
                        <InputField id="notification-target" name="target" :label="t('Email or Slack webhook address')" maxlength="500" required />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <InputField id="notification-threshold" name="threshold" type="number" min="1" :label="t('Spike at (current visitors)')" :description="t('For spike alerts only.')" />
                            <SelectField id="notification-view" name="saved_view_id" :label="t('Saved view')" :options="viewOptions" :description="t('For CSV exports: whose filters to use. Save a view from the report first.')" />
                        </div>
                    </FormDialog>
                </div>
            </AcmeCard>

            <AcmeCard v-if="tab === 'sharing' && data.sharing" :padded="false" :title="t('Share the report')" :description="t('Give clients or your team a read-only link to this site’s report. They don’t need an account. Releases and goal settings aren’t shown.')">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <template v-if="data.sharing.url">
                        <CodeBlock :code="data.sharing.url" class="whitespace-pre-wrap break-all" />
                        <p class="text-sm text-muted">
                            <Rich :text="data.sharing.password ? t('Protected by a password. Shared :time.') : t('Anyone with the link can see it. Shared :time.')"><template #time><RelativeTime v-if="data.sharing.sharedAt" :at="data.sharing.sharedAt" /></template></Rich>
                        </p>
                        <template v-if="embedCode">
                            <p class="text-sm font-bold text-ink">{{ t('Embed it in another page') }}</p>
                            <CodeBlock :code="embedCode" class="whitespace-pre-wrap break-all" />
                        </template>
                    </template>
                    <div class="flex flex-wrap gap-2">
                        <FormDialog id="share-report" :title="data.sharing.url ? t('Update sharing') : t('Share the report')" :action="`${base}/share`" :submit="data.sharing.url ? t('Update sharing') : t('Create link')">
                            <template #trigger="{ open }"><AcmeBtn :variant="data.sharing.url ? 'secondary' : 'primary'" size="sm" @click="open">{{ data.sharing.url ? t('Update sharing') : t('Create link') }}</AcmeBtn></template>
                            <PasswordField id="share-password" name="share_password" :label="t('Password (optional)')" :description="t('At least 8 characters. Leave empty for no password.')" autocomplete="new-password" />
                            <CheckboxField v-if="data.sharing.url" id="share-new-link" name="new_link" :label="t('New link (the old one stops working)')" />
                        </FormDialog>
                        <DeleteDialog v-if="data.sharing.url" id="stop-sharing" :title="t('Stop sharing?')" :description="t('The link stops working at once.')" :action="`${base}/share`" :submit-label="t('Stop sharing')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Stop sharing') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </div>
                </div>
            </AcmeCard>

            <AcmeCard v-if="tab === 'settings'" :title="t('Site settings')" :description="t('Its name, hostnames, time zone, paths and addresses to ignore, content groups, custom properties and referrers to treat as spam.')">
                <AcmeBtn icon="config" :to="{ query: { ...route.query, dialog: 'site-settings' } }">{{ t('Edit settings') }}</AcmeBtn>
            </AcmeCard>
            <AcmeCard v-if="tab === 'settings'" :padded="false" :title="t('Delete this site')" :description="t('Deletes its visits, goals and reports. The snippet stops being accepted immediately.')">
                <div class="p-4 sm:p-6">
                    <DeleteDialog id="delete-site" :title="t('Delete :site?', { site: site.name })" :description="t('All of its analytics data is deleted.')" :action="base" :submit-label="t('Delete site')">
                        <template #trigger="{ open }"><AcmeBtn variant="danger" @click="open">{{ t('Delete :site', { site: site.name }) }}</AcmeBtn></template>
                    </DeleteDialog>
                </div>
            </AcmeCard>

            <SiteSettingsDialog :project-id="project.id" :site-id="site.id" :page="data" />
        </template>
        </div>
    </div>
</template>
