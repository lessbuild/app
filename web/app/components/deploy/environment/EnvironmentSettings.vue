<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';
import type { Option } from '~/types/ui';

/** How deploys run in an environment: approval, strategy, safety nets, runtime, replicas, and the build server. */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t, tc } = useT();
const environment = computed(() => props.page.environment);
const text = (value: number | null) => (value === null ? '' : String(value));
const strategy = ref<string | null>(environment.value.strategy);
const observation = ref<string | null>(text(environment.value.observationMinutes));
const errorRate = ref<string | null>(text(environment.value.rollbackErrorRatePercent));
const latency = ref<string | null>(text(environment.value.rollbackLatencyPercent));
const conversion = ref<string | null>(text(environment.value.rollbackConversionDropPercent));
const runtime = ref<string | null>(environment.value.runtime);
const buildServer = ref<string | null>(text(environment.value.buildServerId));
const bucket = ref<string | null>(text(environment.value.artifactBucketId));
const strategies = computed<Option[]>(() => [
    { value: 'blue_green', label: t('Blue-green (switch when ready)') },
    { value: 'canary', label: t('Canary (check the new release first)') },
    { value: 'rolling', label: t('Rolling (restart workers one by one)') },
]);
const observations = computed<Option[]>(() => [5, 10, 15, 30].map((minutes) => ({ value: String(minutes), label: tc(':count minute|:count minutes', minutes, { count: minutes }) })));
const errorRates = computed<Option[]>(() => [1, 2, 5, 10, 25].map((percent) => ({ value: String(percent), label: t('Over :percent% of requests failing', { percent }) })));
const latencies = computed<Option[]>(() => [25, 50, 100, 200].map((percent) => ({ value: String(percent), label: t('Over :percent% slower', { percent }) })));
const conversions = computed<Option[]>(() => [10, 20, 30, 50].map((percent) => ({ value: String(percent), label: t('Down by over :percent%', { percent }) })));
const runtimes: Option[] = [
    { value: 'php', label: 'PHP' },
    { value: 'node', label: 'Node.js' },
    { value: 'python', label: 'Python' },
    { value: 'docker', label: 'Docker' },
    { value: 'compose', label: 'Docker Compose' },
];
</script>

<template>
    <div class="space-y-10">
        <SettingsSection id="settings" :title="t('How deploys run')" :description="t('Approval, strategy, safety nets, runtime and replicas.')">
            <ApiForm :action="`${base}/settings`" method="PUT" class="p-4 sm:p-6">
                <fieldset :disabled="!page.canManage" class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-1 sm:col-span-2">
                        <ToggleField name="requires_deployment_approval" :label="t('Deploys need approval from someone else')" :checked="environment.requiresApproval" />
                        <ToggleField name="automatic_rollback" :label="t('Roll back automatically when a live deploy fails')" :checked="environment.automaticRollback" />
                        <ToggleField
                            name="migration_safety"
                            :label="t('Stop before destructive migrations')"
                            :checked="environment.migrationSafety"
                            :description="t('Laravel apps: before migrating, lists the SQL pending migrations would run, and stops the deploy if any of it drops, truncates or renames, until someone who can approve deploys approves it.')"
                        />
                    </div>
                    <SelectField v-model="strategy" name="deployment_strategy" :label="t('Strategy')" :options="strategies" />
                    <InputField name="rolling_pause_seconds" type="number" min="0" max="30" :label="t('Pause between workers (seconds)')" :model-value="String(environment.rollingPauseSeconds)" />
                    <SelectField v-model="observation" name="post_deployment_observation_minutes" :label="t('Watch health after each deploy')" :placeholder="t('Don’t watch')" :options="observations" />
                    <SelectField
                        v-model="errorRate"
                        name="rollback_error_rate_percent"
                        :label="t('Fail the deploy when errors jump')"
                        :placeholder="t('Only check health')"
                        :options="errorRates"
                        :description="t('While watching, compares failed requests since the deploy with the same time before it, from this environment’s Monitoring telemetry. Needs at least 20 requests.')"
                    />
                    <SelectField
                        v-model="latency"
                        name="rollback_latency_percent"
                        :label="t('Fail the deploy when it gets slower')"
                        :placeholder="t('Don’t compare latency')"
                        :options="latencies"
                        :description="t('Compares average request time since the deploy with the same time before it. Needs at least 20 requests each side.')"
                    />
                    <SelectField
                        v-model="conversion"
                        name="rollback_conversion_drop_percent"
                        :label="t('Fail the deploy when conversions drop')"
                        :placeholder="t('Don’t compare conversions')"
                        :options="conversions"
                        :description="t('Compares the goal conversion rate of this environment’s Analytics site since the deploy with the same time before it. Needs at least 50 visits each side.')"
                    />
                    <SelectField v-model="runtime" name="runtime_type" :label="t('Runtime')" :options="runtimes" />
                    <InputField name="runtime_version" :label="t('Version (optional)')" :model-value="environment.runtimeVersion" placeholder="22" maxlength="20" />
                    <InputField name="container_port" type="number" min="1" max="65535" :label="t('App port (Node, Python, Docker)')" :model-value="text(environment.containerPort)" />
                    <InputField name="build_command" :label="t('Build command')" :model-value="environment.buildCommand" maxlength="2000" />
                    <InputField name="start_command" :label="t('Start command')" :model-value="environment.startCommand" placeholder="node server.js" maxlength="2000" />
                    <InputField
                        name="dockerfile_path"
                        :label="t('Dockerfile or Compose file')"
                        :model-value="environment.dockerfilePath"
                        placeholder="Dockerfile"
                        maxlength="255"
                        :description="t('For Docker Compose, the Compose file (compose.yaml by default).')"
                    />
                    <InputField
                        name="compose_service"
                        :label="t('Compose web service')"
                        :model-value="environment.composeService"
                        placeholder="web"
                        maxlength="63"
                        :description="t('Docker Compose only: the service that serves the website, on the container port above. Other services (a database, a worker) run beside it, and named volumes are kept between deploys.')"
                    />
                    <div class="grid grid-cols-3 gap-3 sm:col-span-2">
                        <InputField name="minimum_replicas" type="number" min="1" max="20" :label="t('Min replicas')" :model-value="String(environment.minimumReplicas)" />
                        <InputField name="desired_replicas" type="number" min="1" max="20" :label="t('Running')" :model-value="String(environment.desiredReplicas)" />
                        <InputField name="maximum_replicas" type="number" min="1" max="20" :label="t('Max replicas')" :model-value="String(environment.maximumReplicas)" />
                    </div>
                    <div class="grid items-end gap-3 sm:col-span-2 sm:grid-cols-2">
                        <ToggleField
                            name="autoscale_enabled"
                            :label="t('Scale automatically')"
                            :checked="environment.autoscaleEnabled"
                            :description="t('Adds a replica when the servers’ average CPU stays above the target for a few minutes, and removes one when it stays under half of it for ten; always between the minimum and maximum.')"
                        />
                        <InputField name="autoscale_cpu_target" type="number" min="20" max="95" :label="t('Target CPU (%)')" :model-value="String(environment.autoscaleCpuTarget)" />
                        <InputField
                            name="autoscale_queue_jobs"
                            type="number"
                            min="1"
                            max="100000"
                            :label="t('Waiting jobs per replica (optional)')"
                            :model-value="text(environment.autoscaleQueueJobs)"
                            :description="t('Scale up when this environment’s queue monitors report more waiting jobs than this for each replica, even while CPU is low.')"
                        />
                        <p v-if="environment.autoscaledAt" class="text-xs text-muted sm:col-span-2">
                            <Rich :text="t('Last scaled automatically :when.')"><template #when><RelativeTime :at="environment.autoscaledAt" /></template></Rich>
                        </p>
                    </div>
                    <div v-if="page.canManage" class="flex justify-end sm:col-span-2"><SubmitButton>{{ t('Save settings') }}</SubmitButton></div>
                </fieldset>
            </ApiForm>
        </SettingsSection>

        <SettingsSection
            id="build-server"
            :title="t('Build server')"
            :description="t('Build on another of your servers so installing dependencies and compiling assets doesn’t slow the websites. The built release passes through one of this project’s storage buckets with links that expire, and the website’s server only unpacks it and makes it live. Docker builds stay on the website’s server.')"
        >
            <ApiForm :action="`${base}/build-server`" method="PUT" class="p-4 sm:p-6">
                <fieldset :disabled="!page.canManage" class="grid items-end gap-4 sm:grid-cols-3">
                    <SelectField v-model="buildServer" name="build_server_id" :label="t('Build on')" :placeholder="t('Each website’s own server')" :options="page.buildServers" />
                    <SelectField v-model="bucket" name="artifact_bucket_id" :label="t('Storage bucket')" :placeholder="t('None')" :options="page.storageBuckets" />
                    <div v-if="page.canManage"><SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton></div>
                    <p v-if="page.storageBuckets.length === 0" class="text-xs text-muted sm:col-span-3">{{ t('Add a storage bucket to this project under Infrastructure → Storage first.') }}</p>
                </fieldset>
            </ApiForm>
        </SettingsSection>
    </div>
</template>
