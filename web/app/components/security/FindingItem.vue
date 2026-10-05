<script setup lang="ts">
import type { FindingRow } from '~/types/security';

/**
 * One finding as an expandable row (the Acme theme's finding row): a bar for how serious it is, what it is and where,
 * then what it means and how to fix it. People who manage Security can apply a server fix, ignore it with a reason, or
 * reopen it; each opens a dialog first.
 */
const props = defineProps<{ finding: FindingRow; base: string; canManage: boolean }>();
const { t } = useT();
const open = ref(false);
const action = computed(() => `${props.base}/findings/${props.finding.id}`);
const bar: Record<string, string> = { critical: 'bg-rose-600', high: 'bg-rose-400', medium: 'bg-amber-400', low: 'bg-zinc-300 dark:bg-zinc-600', info: 'bg-sky-400' };
</script>

<template>
    <li>
        <button type="button" class="flex w-full items-center gap-4 px-5 py-4 text-left hover:bg-black/[.02] sm:px-6 dark:hover:bg-white/[.03]" :aria-expanded="open" @click="open = !open">
            <span :class="['h-10 w-1 shrink-0 rounded-full', bar[finding.severity] ?? 'bg-zinc-300']" aria-hidden="true" />
            <span class="min-w-0 flex-1">
                <b :class="['block truncate font-medium', finding.status === 'open' ? 'text-ink' : 'text-muted']">{{ finding.title }}</b>
                <span class="block truncate text-xs text-muted">
                    {{ finding.sourceLabel }}<template v-if="finding.subject"> · {{ finding.subject }}</template> ·
                    <Rich v-if="finding.status === 'resolved' && finding.resolvedAt" :text="t('resolved :time')"><template #time><RelativeTime :at="finding.resolvedAt" /></template></Rich>
                    <Rich v-else :text="t('seen :time')"><template #time><RelativeTime :at="finding.lastSeenAt" /></template></Rich>
                </span>
            </span>
            <AcmeBadge :tone="acmeTone(finding.tone)" class="hidden sm:inline-flex">{{ finding.severityLabel }}</AcmeBadge>
            <AcmeBadge v-if="finding.fixing" tone="blue" dot>{{ t('Fixing…') }}</AcmeBadge>
            <AcmeBadge v-else-if="finding.status !== 'open'" :tone="finding.status === 'resolved' ? 'green' : 'gray'" dot>{{ finding.status === 'resolved' ? t('Resolved') : t('Ignored') }}</AcmeBadge>
            <AcmeIcon name="chevronDown" :size="16" :class="['shrink-0 text-muted transition', open && 'rotate-180']" />
        </button>
        <div v-if="open" class="space-y-3 px-5 pb-5 pl-10 text-sm sm:px-6 sm:pl-11">
            <p v-if="finding.detail" class="text-ink">{{ finding.detail }}</p>
            <p v-if="finding.fix" class="rounded-xl border border-line bg-black/[.02] p-3 dark:bg-white/[.03]"><b class="font-medium text-ink">{{ t('Fix:') }}</b> <span class="text-muted">{{ finding.fix }}</span></p>
            <p v-if="finding.status === 'ignored' && finding.ignoredReason" class="text-muted">{{ t('Ignored: :reason', { reason: finding.ignoredReason }) }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="canManage && finding.status === 'open' && finding.fixAction && !finding.fixing">
                    <FormDialog :id="`fix-${finding.id}`" :title="finding.fixLabel ?? t('Apply the fix')" :description="finding.fixWarning ?? undefined" :action="`${action}/fix`" :submit="t('Apply')">
                        <template #trigger="{ open: show }"><AcmeBtn variant="primary" size="sm" @click="show">{{ finding.fixLabel }}</AcmeBtn></template>
                        <AcmeAlert v-if="finding.fixError" tone="danger">{{ t('Last attempt failed: :error', { error: finding.fixError }) }}</AcmeAlert>
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
                    <template #trigger="{ open: show }"><AcmeBtn size="sm" @click="show">{{ t('Ignore') }}</AcmeBtn></template>
                    <input type="hidden" name="status" value="ignored">
                    <TextareaField :id="`ignore-reason-${finding.id}`" name="reason" :label="t('Why')" rows="3" maxlength="1000" :placeholder="t('A false positive, or a risk we accept because…')" />
                </FormDialog>
                <ApiForm v-else-if="canManage && finding.status === 'ignored'" :action="action" method="PUT">
                    <input type="hidden" name="status" value="open">
                    <SubmitButton variant="secondary" size="sm">{{ t('Reopen') }}</SubmitButton>
                </ApiForm>
                <a v-if="finding.url" :href="finding.url" class="ml-auto text-xs font-medium text-ink underline" target="_blank" rel="noopener noreferrer">{{ t('Details') }}</a>
            </div>
        </div>
    </li>
</template>
