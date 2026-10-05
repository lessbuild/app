<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** Search engines and a cache installed on a server in one go, with their addresses and keys. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t } = useT();
const listens = computed(() => [
    { value: 'local', label: t('This server') },
    { value: 'private', label: t('Private network too'), disabled: props.page.server.privateIp === null },
]);
</script>

<template>
    <AcmeCard
:padded="false"
        :title="t('Services')"
        :description="t('Install a search engine or a cache on this server. Each gets a generated key or password and listens on the server itself; share it over the private network to use it from your other servers.')"
    >
        <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
            <div v-for="service in page.services" :key="service.kind" class="grid gap-3 border-b border-line pb-4 last:border-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                <div class="min-w-0">
                    <p class="font-bold text-ink">{{ service.name }}</p>
                    <p class="text-sm text-muted">{{ service.description }}</p>
                    <template v-if="service.installed">
                        <TaskStatusBadge :status="service.installed.status" :error="service.installed.error" />
                        <dl v-if="service.installed.status === 'active'" class="mt-2 grid gap-1 text-xs">
                            <div>
                                <dt class="inline font-bold">{{ t('Address') }}:</dt>
                                <dd class="inline font-mono">{{ service.installed.address }}<template v-if="service.installed.privateAddress"> · {{ service.installed.privateAddress }}</template></dd>
                            </div>
                            <div v-if="service.installed.secret">
                                <dt class="inline font-bold">{{ service.secretLabel }}:</dt>
                                <dd class="inline"><details class="inline"><summary class="inline cursor-pointer text-primary underline">{{ t('Show') }}</summary> <code class="break-all font-mono">{{ service.installed.secret }}</code></details></dd>
                            </div>
                        </dl>
                    </template>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <ApiForm :action="`${base}/services`" class="flex flex-wrap items-end gap-2">
                        <input type="hidden" name="kind" :value="service.kind">
                        <SelectField :id="`listen-${service.kind}`" name="listen" :label="t('Reachable from')" :options="listens" :model-value="service.installed?.listen ?? 'local'" />
                        <SubmitButton :variant="service.installed ? 'secondary' : 'primary'" size="sm" :disabled="service.installed !== null && ['pending', 'removing'].includes(service.installed.status)">
                            {{ service.installed ? t('Apply') : t('Install') }}
                        </SubmitButton>
                    </ApiForm>
                    <ApiForm v-if="service.installed && service.installed.status !== 'removing'" :action="`${base}/services/${service.installed.id}`" method="DELETE">
                        <SubmitButton variant="quiet" size="sm">{{ t('Uninstall') }}</SubmitButton>
                    </ApiForm>
                </div>
            </div>
        </div>
    </AcmeCard>
</template>
