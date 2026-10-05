<script setup lang="ts">
import type { WebsiteDetail, WebsiteFormOptions } from '~/types/infrastructure';

/**
 * A website's fields (the Acme theme's create website form): the website itself, then its environment and health
 * checks, which show their settings once they're on. Side by side when there's room. `website` is null when creating.
 */
const props = withDefaults(defineProps<{ options: WebsiteFormOptions; website?: WebsiteDetail | null }>(), { website: null });
const { t, tc } = useT();
const server = ref<string | null>(props.website?.serverId != null ? String(props.website.serverId) : (props.options.hosts[0]?.value ?? null));
const environment = ref<string | null>(props.website?.environmentId ?? '');
const interval = ref<string | null>(String(props.website?.healthCheckIntervalMinutes ?? 5));
const threshold = ref<string | null>(String(props.website?.healthFailureThreshold ?? 3));
const health = ref(props.website?.healthCheckEnabled ?? false);
const intervals = computed(() => props.options.healthIntervals.map((minutes) => ({ value: String(minutes), label: tc(':count minute|:count minutes', minutes, { count: minutes }) })));
const thresholds = computed(() => props.options.failureThresholds.map((count) => ({ value: String(count), label: tc(':count failed check|:count failed checks', count, { count }) })));
</script>

<template>
    <div class="@container">
        <div class="grid gap-6 @3xl:grid-cols-2">
            <fieldset class="grid content-start gap-4 rounded-2xl border border-line bg-surface p-5 shadow-card">
                <legend class="float-left mb-1 font-semibold text-ink">{{ t('Website') }}</legend>
                <InputField name="name" :label="t('Name')" :model-value="website?.name" maxlength="255" placeholder="Shop" required />
                <SelectField v-model="server" name="server_id" :label="t('Server')" :options="options.hosts" required />
                <InputField name="url" :label="t('Domain')" :model-value="website?.url" maxlength="255" placeholder="shop.example.com" :description="t('Point its DNS at the server; Caddy gets the certificate.')" required />
                <InputField name="release_retention" type="number" min="2" max="20" :label="t('Releases to keep')" :model-value="String(website?.releaseRetention ?? 5)" />
                <TextareaField name="description" :label="t('Description')" :model-value="website?.description" rows="2" maxlength="2000" />
            </fieldset>
            <fieldset class="grid content-start gap-4 rounded-2xl border border-line bg-surface p-5 shadow-card">
                <legend class="float-left mb-1 font-semibold text-ink">{{ t('Environment and health') }}</legend>
                <SelectField v-model="environment" name="environment_id" :label="t('Serves environment')" :placeholder="t('Not linked')" :options="options.environments" :description="t('Link it to a project environment for deployments and health checks.')" />
                <ToggleField id="website-health" name="health_check_enabled" :label="t('Check the website’s health')" :checked="health" :description="t('Checked by Monitoring in the linked environment, with its incidents and alerts.')" @toggle="health = $event" />
                <div v-show="health" class="grid gap-4">
                    <InputField name="health_check_path" :label="t('Health check path')" :model-value="website?.healthCheckPath ?? '/'" maxlength="255" />
                    <ToggleField
                        id="website-self-healing"
                        name="self_healing"
                        :label="t('Heal it automatically')"
                        :checked="website?.selfHealing ?? false"
                        :description="t('When the health check fails, restart Caddy, PHP-FPM or the website’s workers if they’ve stopped (or reload them), at most three times an hour. What happened goes on the incident.')"
                    />
                    <div class="grid gap-4 @lg:grid-cols-2">
                        <SelectField v-model="interval" name="health_check_interval_minutes" :label="t('Every')" :options="intervals" />
                        <SelectField v-model="threshold" name="health_failure_threshold" :label="t('Incident after')" :options="thresholds" />
                    </div>
                </div>
            </fieldset>
            <div class="rounded-2xl border border-line bg-surface p-5 shadow-card @3xl:col-span-2">
                <TextareaField name="env_file" :label="t('.env file')" :model-value="website?.envFile" rows="6" class="font-mono text-xs" :description="t('Stored encrypted and written to the server. Changing it sets the website up again.')" spellcheck="false" />
            </div>
        </div>
    </div>
</template>
