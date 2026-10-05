<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** A website's settings: its PHP version, Reverb, its own Caddy directives, its details, and deleting it. */
const props = defineProps<{ page: WebsitePage; base: string }>();
const { t } = useT();
const website = computed(() => props.page.website);
const php = ref<string | null>(website.value.phpVersion);
const phpVersions = computed(() => props.page.phpVersions.map((version) => ({ value: version, label: version === props.page.defaultPhpVersion ? `PHP ${version} (${t('default')})` : `PHP ${version}` })));
</script>

<template>
    <div class="space-y-6">
        <AcmeCard
id="php-version"
            :padded="false"
            :title="t('PHP version')"
            :description="t('The website’s PHP-FPM version. Switching installs it on the server if needed (other websites keep theirs) and points this site at it; the next deploy reloads it too.')"
        >
            <ApiForm :action="`${base}/php-version`" method="PUT" class="flex flex-wrap items-end gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <SelectField v-model="php" name="php_version" :label="t('PHP')" :options="phpVersions" />
                <SubmitButton variant="secondary" :disabled="website.provisioning">{{ t('Switch') }}</SubmitButton>
            </ApiForm>
        </AcmeCard>
        <AcmeCard
id="reverb"
            :padded="false"
            :title="t('WebSockets (Laravel Reverb)')"
            :description="t('Runs php artisan reverb:start for this website, kept alive by Supervisor on its own port, and sends /app and /apps to it so browsers connect over the site’s own address and certificate.')"
        >
            <div class="flex flex-wrap items-center gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <p v-if="website.reverb" class="text-sm text-muted">{{ t('Reverb is set up. Manage its process on the server’s Processes tab.') }}</p>
                <ApiForm v-else :action="`${base}/reverb`"><SubmitButton variant="secondary" :disabled="website.provisioning">{{ t('Set up Reverb') }}</SubmitButton></ApiForm>
            </div>
        </AcmeCard>
        <AcmeCard
id="web-server"
            :padded="false"
            :title="t('Web server (Caddy)')"
            :description="t('Add your own Caddy directives (headers, redirects, rewrites, basic auth…) inside this website’s site block. Caddy checks the whole configuration before using it, so a mistake never takes the site down.')"
        >
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <AcmeAlert v-if="website.caddyError" tone="danger" role="alert">
                    <strong>{{ t('Caddy refused the last change; the previous configuration is still in use.') }}</strong> <span class="font-mono text-xs">{{ website.caddyError }}</span>
                </AcmeAlert>
                <ApiForm :action="`${base}/caddy`" method="PUT" class="grid gap-3">
                    <TextareaField
                        name="caddy_directives"
                        :label="t('Your directives')"
                        :model-value="website.caddyDirectives"
                        rows="6"
                        maxlength="5000"
                        class="font-mono text-sm"
                        :placeholder="'header X-Frame-Options DENY\nredir /old-page /new-page 301'"
                        spellcheck="false"
                    />
                    <div><SubmitButton variant="secondary" :disabled="website.provisioning">{{ t('Save and apply') }}</SubmitButton></div>
                </ApiForm>
                <Disclosure v-if="page.caddyConfiguration" :title="t('See the full configuration')">
                    <CodeBlock :code="page.caddyConfiguration" class="whitespace-pre-wrap" />
                </Disclosure>
            </div>
        </AcmeCard>
        <section v-if="page.options" class="space-y-4">
            <div>
                <h2 class="font-semibold text-ink">{{ t('Settings') }}</h2>
                <p class="mt-0.5 text-sm text-muted">{{ t('A new server, domain or .env sets the website up again. Moving servers keeps the old copy until the new one is live.') }}</p>
            </div>
            <ApiForm :action="base" method="PUT" class="grid gap-4">
                <WebsiteFields :options="page.options" :website="website" />
                <div class="flex justify-end"><SubmitButton :disabled="website.provisioning">{{ t('Save website') }}</SubmitButton></div>
            </ApiForm>
        </section>
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-rose-500/30 bg-rose-500/[.03] p-5">
            <span>
                <b class="block font-semibold text-rose-700 dark:text-rose-300">{{ t('Delete this website') }}</b>
                <span class="text-sm text-muted">{{ t('Its files, Caddy site and database are removed from the server. This can’t be undone.') }}</span>
            </span>
            <DeleteDialog id="delete-website" :title="t('Delete :website?', { website: website.name })" :description="t('The files and database on the server are deleted too.')" :action="base" :submit-label="t('Delete website')">
                <template #trigger="{ open }"><AcmeBtn variant="danger" @click="open">{{ t('Delete website') }}</AcmeBtn></template>
            </DeleteDialog>
        </section>
    </div>
</template>
