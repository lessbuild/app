<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** The ports opened in a server's firewall beyond SSH and the web, and letting the account's other servers in. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t } = useT();
const server = computed(() => props.page.server);
</script>

<template>
    <div class="space-y-6">
        <AcmeCard
:padded="false"
            :title="t('Firewall')"
            :description="t('Everything else is closed. SSH (and ports 80 and 443 on web servers) were opened when the server was set up; open more here, for everyone or only one address or network.')"
        >
            <template #action>
                <FormDialog id="add-rule" :title="t('Open a port')" :action="`${base}/firewall-rules`" :submit="t('Open port')">
                    <template #trigger="{ open }"><AcmeBtn size="sm" icon="plus" @click="open">{{ t('Open a port') }}</AcmeBtn></template>
                    <FirewallFields :rule="null" prefix="add-rule" />
                </FormDialog>
            </template>
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <div v-for="rule in page.firewallRules" :key="rule.id" class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                    <div class="min-w-0">
                        <p class="font-bold text-ink">{{ rule.name }}</p>
                        <p class="text-xs text-muted"><span class="font-mono">{{ rule.port }}/{{ rule.protocol }}</span> · {{ t('from :source', { source: rule.from }) }}</p>
                        <TaskStatusBadge :status="rule.status" :error="rule.error" />
                    </div>
                    <span v-if="rule.status !== 'removing'" class="flex shrink-0 items-center gap-2">
                        <FormDialog :id="`edit-rule-${rule.id}`" :title="t('Edit firewall rule')" :action="`${base}/firewall-rules/${rule.id}`" method="PUT" :submit="t('Save')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Edit') }}</AcmeBtn></template>
                            <FirewallFields :rule="rule" :prefix="`edit-rule-${rule.id}`" />
                        </FormDialog>
                        <ApiForm :action="`${base}/firewall-rules/${rule.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                    </span>
                </div>
                <p v-if="page.firewallRules.length === 0" class="text-sm text-muted">{{ t('No extra ports are open.') }}</p>
            </div>
        </AcmeCard>
        <AcmeCard
:padded="false"
            :title="t('Private network')"
            :description="server.privateIp
                ? t('This server’s private IP is :ip. Traffic between servers at the same provider and region can stay on the private network; letting your other servers in opens every TCP port to their private IPs only.', { ip: server.privateIp })
                : t('This server has no private IP. Most providers give servers in the same region one automatically.')"
        >
            <ApiForm :action="`${base}/private-network`" method="PUT" class="flex flex-wrap items-center justify-between gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <input type="hidden" name="trust" :value="server.trustPrivateNetwork ? '0' : '1'">
                <p class="text-sm text-muted">{{ server.trustPrivateNetwork ? t('Your other servers in this region are let in.') : t('Your other servers aren’t let in yet.') }}</p>
                <SubmitButton variant="secondary" size="sm" :disabled="server.privateIp === null">{{ server.trustPrivateNetwork ? t('Stop letting them in') : t('Let my other servers in') }}</SubmitButton>
            </ApiForm>
        </AcmeCard>
    </div>
</template>
