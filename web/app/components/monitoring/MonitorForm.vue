<script setup lang="ts">
import type { MonitorForm } from '~/types/monitoring';
import type { Option } from '~/types/ui';

/**
 * Add or edit a monitor: what it checks (the fields depend on its type, which can't change once made), how often, and
 * where its alerts go. Secrets it holds are never sent back; leaving those fields blank keeps them.
 */
const props = defineProps<{ form: MonitorForm; projectId: string; environments: Option[]; type: string }>();
const { t, tc } = useT();
const monitor = computed(() => props.form.monitor);
const value = (number: number | null | undefined, fallback: number | string) => (number === null || number === undefined ? String(fallback) : String(number));
const environment = ref<string | null>(monitor.value?.environmentId ?? props.environments[0]?.value ?? null);
const environmentOptions = computed(() => (monitor.value ? props.environments.filter((option) => option.value === monitor.value?.environmentId) : props.environments));
const schedule = ref<string | null>(monitor.value?.heartbeatSchedule ?? 'interval');
const method = ref<string | null>(monitor.value?.method ?? 'GET');
const recordType = ref<string | null>(monitor.value?.dnsRecordType ?? 'A');
const match = ref<string | null>(monitor.value?.dnsMatch ?? 'contains');
const interval = ref<string | null>(value(monitor.value?.intervalMinutes, 5));
const schedules = computed(() => [{ value: 'interval', label: t('Every few minutes, after the last run') }, { value: 'cron', label: t('A cron schedule') }]);
const matches = computed(() => [{ value: 'contains', label: t('Contains all expected records') }, { value: 'exact', label: t('Exactly the expected records') }]);
const methods = [{ value: 'GET', label: 'GET' }, { value: 'HEAD', label: 'HEAD' }];
const recordTypes = computed(() => props.form.dnsTypes.map((type) => ({ value: type, label: type })));
const action = computed(() => `/api/app/projects/${props.projectId}/monitoring/monitors${monitor.value ? `/${monitor.value.id}` : ''}`);
const nowLabel = computed(() => (monitor.value ? t('Now: :target. Leave blank to keep it.', { target: monitor.value.target }) : ''));
</script>

<template>
    <ApiForm :action="action" :method="monitor ? 'PUT' : 'POST'" class="grid gap-6">
        <input v-if="monitor" type="hidden" name="version" :value="monitor.version">
        <input type="hidden" name="check_type" :value="type">
        <p class="text-sm font-bold text-ink">{{ form.types[type] }}<span v-if="monitor" class="font-normal text-muted"> · {{ t('the type can’t be changed') }}</span></p>

        <div class="grid gap-5 sm:grid-cols-2">
            <InputField name="name" :label="t('Name')" :model-value="monitor?.name" :description="t('Shown in alerts. Don’t put secrets in it.')" maxlength="120" required autofocus />
            <SelectField v-model="environment" name="environment_id" :label="t('Environment')" :options="environmentOptions" :description="monitor ? t('The environment can’t be changed.') : undefined" required />
        </div>

        <template v-if="type === 'queue'">
            <InputField
                name="queue_name"
                :label="t('Queue label')"
                :model-value="monitor?.queueName"
                :description="t('One monitor per queue and environment, such as “emails”. Your collector sends queue counts and each worker sends its own heartbeat; no credentials or job payloads are collected.')"
                maxlength="120"
                placeholder="emails"
                required
            />
            <div class="grid gap-5 sm:grid-cols-2">
                <InputField
                    v-for="setting in form.queueSettings"
                    :id="`queue-settings-${setting.field}`"
                    :key="setting.field"
                    :name="`queue_settings[${setting.field}]`"
                    :error-key="`queue_settings.${setting.field}`"
                    :label="setting.label"
                    type="number"
                    :min="setting.min"
                    :max="setting.max"
                    :model-value="monitor ? (monitor.queueSettings[setting.field] === null || monitor.queueSettings[setting.field] === undefined ? '' : String(monitor.queueSettings[setting.field])) : (setting.default === null ? '' : String(setting.default))"
                    :required="setting.required"
                />
            </div>
            <p class="text-xs text-muted">{{ t('Leave a maximum blank to turn it off; zero allows none. One failed observation opens an incident, and every condition must pass to recover. The timeouts also give a new monitor time to receive its first signals.') }}</p>
        </template>

        <template v-else-if="type === 'heartbeat'">
            <div class="grid gap-5 sm:grid-cols-2">
                <SelectField v-model="schedule" name="heartbeat_schedule" :label="t('Expected schedule')" :options="schedules" />
                <InputField name="heartbeat_grace_minutes" :label="t('Grace and longest run (minutes)')" type="number" min="1" max="10080" :model-value="value(monitor?.heartbeatGraceMinutes, 5)" required />
                <InputField v-if="schedule === 'interval'" name="heartbeat_interval_minutes" :label="t('Interval (minutes)')" type="number" min="1" max="43200" :model-value="value(monitor?.heartbeatIntervalMinutes, 60)" />
                <template v-else>
                    <InputField name="heartbeat_cron" :label="t('Cron expression')" :description="t('For the cron schedule: five fields.')" maxlength="100" :model-value="monitor?.heartbeatCron ?? '0 2 * * *'" class="font-mono" />
                    <InputField name="heartbeat_timezone" :label="t('Cron time zone')" maxlength="64" :model-value="monitor?.heartbeatTimezone ?? 'UTC'" placeholder="Europe/London" />
                </template>
            </div>
            <p class="text-xs text-muted">{{ t('A completed run is expected by the scheduled time plus the grace period. A start signal also limits the run to the grace period. One failure or missed deadline opens an incident; the next successful run closes it.') }}</p>
        </template>

        <TextareaField
            v-else-if="type === 'flow'"
            name="flow_steps"
            :label="t('Steps')"
            rows="12"
            maxlength="10000"
            :required="!monitor"
            class="font-mono text-xs"
            :placeholder="form.flowExample"
            spellcheck="false"
            :description="(monitor ? `${t('Stored encrypted; leave blank to keep the current :count steps.', { count: monitor.flowSteps })} ` : '')
                + t('Up to 10 steps, separated by a blank line. Each starts with a method and URL; then header Name: value, form a=1&b=2 or json {…}, expect 200 “text” and extract name (regex). Cookies carry from step to step, and :placeholder inserts an extracted value. Use a dedicated test account.', { placeholder: '{{name}}' })"
        />

        <template v-else-if="type === 'http'">
            <InputField
                name="request_url"
                :label="t('URL')"
                type="password"
                autocomplete="off"
                maxlength="2048"
                :required="!monitor"
                :description="`${nowLabel} ${t('Stored encrypted and never shown in check history. Redirects aren’t followed, so enter the final address.')}`.trim()"
            />
            <div class="grid gap-5 sm:grid-cols-2">
                <SelectField v-model="method" name="method" :label="t('Method')" :options="methods" />
                <InputField name="max_duration_ms" :label="t('Slowest acceptable response (ms)')" :description="t('Optional.')" type="number" min="1" max="20000" :model-value="monitor?.maxDurationMs === null || monitor?.maxDurationMs === undefined ? '' : String(monitor.maxDurationMs)" />
                <InputField name="status_min" :label="t('Lowest accepted status')" type="number" min="100" max="599" :model-value="value(monitor?.statusMin, 200)" required />
                <InputField name="status_max" :label="t('Highest accepted status')" type="number" min="100" max="599" :model-value="value(monitor?.statusMax, 299)" required />
            </div>
            <InputField name="body_contains" :label="t('Response must contain (GET only)')" :description="t('Optional exact text, checked in memory (up to 512 KB).')" type="password" autocomplete="off" maxlength="500" />
            <CheckboxField v-if="monitor?.hasBodyContains" name="clear_body_contains" :label="t('Remove the stored text check')" />
            <InputField name="bearer_token" :label="t('Bearer token (HTTPS only)')" :description="t('Optional. Changing the scheme, host or port removes the stored token unless you enter a new one.')" type="password" autocomplete="off" maxlength="2048" />
            <CheckboxField v-if="monitor?.hasBearerToken" name="clear_bearer_token" :label="t('Remove the stored bearer token')" />
        </template>

        <template v-else>
            <InputField
                name="hostname"
                :label="t('Hostname')"
                autocomplete="off"
                maxlength="254"
                :required="!monitor"
                placeholder="status.example.com"
                :description="`${nowLabel} ${t('No scheme, path or port. International names use their punycode form.')}`.trim()"
            />
            <template v-if="type === 'dns'">
                <div class="grid gap-5 sm:grid-cols-2">
                    <SelectField v-model="recordType" name="dns_record_type" :label="t('Record type')" :options="recordTypes" />
                    <SelectField v-model="match" name="dns_match" :label="t('Match')" :options="matches" />
                </div>
                <TextareaField
                    name="dns_expected"
                    :label="t('Expected records, one per line')"
                    rows="4"
                    :required="!monitor"
                    class="font-mono"
                    :description="t('Up to 20. A/AAAA: an IP address. CNAME/NS: a hostname. MX: priority and hostname, like 10 mail.example.com. TXT: the text without quotes.')
                        + (monitor ? ` ${tc(':count record is stored; leave blank to keep it.|:count records are stored; leave blank to keep them.', monitor.dnsExpected, { count: monitor.dnsExpected })}` : '')"
                />
                <p class="text-xs text-muted">{{ t('Uses this checker’s DNS resolver and its cache. It doesn’t verify DNSSEC or global propagation.') }}</p>
            </template>
            <template v-else-if="type === 'tls'">
                <div class="grid gap-5 sm:grid-cols-2">
                    <InputField name="tls_port" :label="t('Port')" type="number" min="1" max="65535" :model-value="value(monitor?.tlsPort, 443)" required />
                    <InputField name="tls_expiry_days" :label="t('Warn this many days before expiry')" type="number" min="1" max="90" :model-value="value(monitor?.tlsExpiryDays, 14)" required />
                </div>
                <p class="text-xs text-muted">{{ t('A TLS 1.2+ handshake with hostname and chain verification; no HTTP request is sent. It checks the leaf certificate only, and STARTTLS isn’t supported.') }}</p>
            </template>
            <template v-else>
                <InputField name="tcp_port" :label="t('Port')" type="number" min="1" max="65535" :model-value="value(monitor?.tcpPort, 5432)" required />
                <p class="text-xs text-muted">{{ t('Opens a TCP connection to every public address of the hostname. Nothing is sent over it.') }}</p>
            </template>
        </template>

        <div v-if="!['heartbeat', 'queue'].includes(type)" class="grid gap-5 sm:grid-cols-2">
            <SelectField v-model="interval" name="interval_minutes" :label="t('Check every')" :options="form.intervals" />
            <InputField name="timeout_seconds" :label="t('Timeout (seconds)')" type="number" min="1" max="20" :model-value="value(monitor?.timeoutSeconds, 10)" required />
            <InputField name="trigger_checks" :label="t('Failures in a row to open an incident')" type="number" min="1" max="10" :model-value="value(monitor?.triggerChecks, 2)" required />
            <InputField name="recovery_checks" :label="t('Passes in a row to recover')" type="number" min="1" max="10" :model-value="value(monitor?.recoveryChecks, 2)" required />
        </div>

        <CheckboxField
            name="enabled"
            unchecked-value="0"
            :label="t('Monitor is on')"
            :checked="monitor?.enabled ?? true"
            :description="t('A paused monitor keeps its open incident. Changing what it checks closes that incident as “monitor changed”, never as recovered.')"
        />

        <fieldset class="grid gap-2 rounded-panel border border-line p-4">
            <legend class="px-1 text-sm font-bold text-ink">{{ t('Alert destinations (up to five)') }}</legend>
            <CheckboxField
                v-for="destination in form.destinations"
                :id="`destination-${destination.id}`"
                :key="destination.id"
                name="destinations[]"
                error-key="destinations"
                :value="String(destination.id)"
                :label="`${destination.name} · ${destination.type}${destination.enabled ? '' : ` · ${t('off')}`}`"
                :checked="monitor?.destinations.includes(destination.id) ?? false"
            />
            <p v-if="form.destinations.length === 0" class="text-sm text-muted">
                {{ t('No alert destinations yet. Incidents still show up under Incidents.') }}
                <NuxtLink :to="`/projects/${projectId}/monitoring/alerts`" class="font-bold text-primary hover:underline">{{ t('Add a destination') }}</NuxtLink>
            </p>
            <CheckboxField name="opened" unchecked-value="0" :label="t('Notify when an incident opens')" :checked="monitor?.notifyOpened ?? true" />
            <CheckboxField name="recovered" unchecked-value="0" :label="t('Notify when it recovers')" :checked="monitor?.notifyRecovered ?? true" />
        </fieldset>

        <div class="flex justify-end"><SubmitButton>{{ monitor ? t('Save monitor') : t('Add monitor') }}</SubmitButton></div>
    </ApiForm>
</template>
