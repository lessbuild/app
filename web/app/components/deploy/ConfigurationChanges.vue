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
    <DataTable :caption="t('Planned changes')">
        <template #head>
            <tr><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('What') }}</th><th scope="col">{{ t('Change') }}</th><th scope="col">{{ t('Fields') }}</th></tr>
        </template>
        <tr v-for="(change, index) in changes" :key="index">
            <td class="font-mono text-xs">{{ change.environment ?? '—' }}</td>
            <td><span class="text-muted">{{ kinds[change.kind] ?? change.kind }}</span> <span class="font-mono text-xs">{{ change.name }}</span></td>
            <td>
                <Badge :tone="actions[change.action]?.tone ?? 'neutral'">{{ actions[change.action]?.label ?? change.action }}</Badge>
                <span v-if="change.requires_approval" class="ml-1 text-xs text-muted">{{ t('needs approval') }}</span>
            </td>
            <td class="font-mono text-xs text-muted">{{ change.fields?.length ? change.fields.join(', ') : '—' }}</td>
        </tr>
    </DataTable>
</template>
