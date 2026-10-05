<script setup lang="ts">
import type { ConfigurationChange } from '~/types/deploy';
import type { Tone } from '~/types/ui';

/** What a configuration plan (or review) changes, one row per object. */
defineProps<{ changes: ConfigurationChange[] }>();
const { t } = useT();
const actions = computed<Record<string, { tone: Tone; label: string }>>(() => ({
    create: { tone: 'info', label: t('Create') },
    update: { tone: 'neutral', label: t('Update') },
    adopt: { tone: 'accent', label: t('Adopt') },
    adoption_required: { tone: 'warning', label: t('Needs adopt: true') },
    remove: { tone: 'danger', label: t('Remove') },
    detach: { tone: 'danger', label: t('Detach') },
    absent: { tone: 'neutral', label: t('Already gone') },
    deploy: { tone: 'info', label: t('Deploy') },
}));
const kinds = computed<Record<string, string>>(() => ({
    environments: t('Environment'),
    processes: t('Process'),
    resources: t('Resource'),
    variables: t('Variable'),
    deploys: t('Deploy'),
}));
</script>

<template>
    <ul class="divide-y divide-line text-sm" :aria-label="t('Planned changes')">
        <li v-for="(change, index) in changes" :key="index" class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0">
            <AcmeBadge :tone="acmeTone(actions[change.action]?.tone)">{{ actions[change.action]?.label ?? change.action }}</AcmeBadge>
            <span class="min-w-0 flex-1 truncate text-ink"><span class="text-muted">{{ kinds[change.kind] ?? change.kind }}</span> <span class="font-mono text-xs">{{ change.name }}</span><template v-if="change.environment"> · <span class="font-mono text-xs text-muted">{{ change.environment }}</span></template></span>
            <span class="text-xs text-muted">{{ change.fields?.length ? change.fields.join(', ') : '' }}<template v-if="change.requires_approval"> · {{ t('needs approval') }}</template></span>
        </li>
        <li v-if="changes.length === 0" class="py-2.5 text-muted">{{ t('Nothing would change.') }}</li>
    </ul>
</template>
