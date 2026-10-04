<script setup lang="ts">
import type { FindingRow } from '~/types/security';

/**
 * One finding: how serious it is, what's wrong and how to fix it. People who manage Security can apply a server fix,
 * ignore it with a reason, or reopen it; each opens a dialog first.
 */
const props = defineProps<{ finding: FindingRow; base: string; canManage: boolean }>();
const { t } = useT();
const action = computed(() => `${props.base}/findings/${props.finding.id}`);
</script>

<template>
    <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2">
                <Badge :tone="finding.tone">{{ finding.severityLabel }}</Badge>
                <span class="text-xs font-bold uppercase tracking-wide text-muted">{{ finding.sourceLabel }}</span>
                <span v-if="finding.subject" class="text-xs text-muted">· {{ finding.subject }}</span>
            </p>
            <p class="mt-1 font-bold text-ink">{{ finding.title }}</p>
            <p v-if="finding.detail" class="mt-1 text-sm text-muted">{{ finding.detail }}</p>
            <p v-if="finding.fix" class="mt-1 text-sm"><span class="font-semibold text-ink">{{ t('Fix:') }}</span> <span class="text-muted">{{ finding.fix }}</span></p>
            <p v-if="finding.status === 'ignored' && finding.ignoredReason" class="mt-1 text-xs text-muted">{{ t('Ignored: :reason', { reason: finding.ignoredReason }) }}</p>
            <p class="mt-1 text-xs text-muted">
                <Rich v-if="finding.status === 'resolved' && finding.resolvedAt" :text="t('Resolved :time')"><template #time><RelativeTime :at="finding.resolvedAt" /></template></Rich>
                <Rich v-else :text="t('Last seen :time')"><template #time><RelativeTime :at="finding.lastSeenAt" /></template></Rich>
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a v-if="finding.url" :href="finding.url" class="ui-btn ui-btn-quiet ui-btn-sm" target="_blank" rel="noopener noreferrer">{{ t('Details') }}</a>
            <template v-if="canManage && finding.status === 'open' && finding.fixAction">
                <Badge v-if="finding.fixing" tone="info">{{ t('Fixing…') }}</Badge>
                <FormDialog v-else :id="`fix-${finding.id}`" :title="finding.fixLabel ?? t('Apply the fix')" :description="finding.fixWarning ?? undefined" :action="`${action}/fix`" :submit="t('Apply')">
                    <template #trigger="{ open }"><UiButton variant="secondary" size="sm" @click="open">{{ finding.fixLabel }}</UiButton></template>
                    <Alert v-if="finding.fixError" tone="danger">{{ t('Last attempt failed: :error', { error: finding.fixError }) }}</Alert>
                </FormDialog>
            </template>
            <FormDialog
                v-if="canManage && finding.status === 'open'"
                :id="`ignore-${finding.id}`"
                :title="t('Ignore this finding')"
                :description="t('It stays ignored through later scans until someone reopens it. Say why, for whoever looks next.')"
                :action="action"
                method="PUT"
                :submit="t('Ignore')"
            >
                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Ignore') }}</UiButton></template>
                <input type="hidden" name="status" value="ignored">
                <TextareaField :id="`ignore-reason-${finding.id}`" name="reason" :label="t('Why')" rows="3" maxlength="1000" :placeholder="t('A false positive, or a risk we accept because…')" />
            </FormDialog>
            <ApiForm v-else-if="canManage && finding.status === 'ignored'" :action="action" method="PUT">
                <input type="hidden" name="status" value="open">
                <SubmitButton variant="quiet" size="sm">{{ t('Reopen') }}</SubmitButton>
            </ApiForm>
        </div>
    </li>
</template>
