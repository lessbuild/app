<script setup lang="ts">
/**
 * "Which would deploy?": paste the paths a change touches, one per line, and see which of the project's repositories a
 * push with them would deploy, using the same subdirectory and path filters as real push deploys. Nothing is deployed.
 */
const props = defineProps<{ projectId: string }>();
type Row = { id: number; name: string; branch: string; website: string | null; pushDeploys: boolean; decision: 'affected' | 'unaffected' | 'unknown'; label: string; matched: string[] };
const { t, tc } = useT();
const paths = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const result = ref<{ paths: number; repositories: Row[] } | null>(null);
const tone = { affected: 'green', unaffected: 'gray', unknown: 'amber' } as const;

/** Ask the API which repositories the paths would deploy. */
async function check() {
    busy.value = true;
    error.value = null;
    result.value = await send<{ paths: number; repositories: Row[] }>('POST', `/projects/${props.projectId}/deploy/impact-preview`, { paths: paths.value }).catch((problem: unknown) => {
        error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
        return null;
    });
    busy.value = false;
}
</script>

<template>
    <UiDialog id="impact-preview" :title="t('Which would deploy?')" :description="t('Paste the paths a change touches, one per line, to see which repositories a push with them would deploy. Nothing is deployed.')" size="wide">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <form class="grid gap-4" @submit.prevent="check">
            <TextareaField id="impact-paths" v-model="paths" name="paths" :label="t('Changed paths')" rows="6" placeholder="apps/api/routes/web.php&#10;packages/ui/button.tsx&#10;docs/readme.md" :description="t('Tip: git diff --name-only main shows them.')" required />
            <div class="flex justify-end"><AcmeBtn type="submit" variant="primary" :loading="busy">{{ t('Check') }}</AcmeBtn></div>
        </form>
        <AcmeAlert v-if="error" tone="danger" class="mt-4">{{ error }}</AcmeAlert>
        <section v-if="result" class="mt-6" :aria-label="t('Result')">
            <p class="text-sm text-muted">{{ tc(':count path checked|:count paths checked', result.paths) }}</p>
            <p v-if="result.repositories.length === 0" class="mt-3 text-sm text-muted">{{ t('No repositories are connected yet.') }}</p>
            <ul v-else class="mt-3 divide-y divide-line rounded-xl border border-line">
                <li v-for="row in result.repositories" :key="row.id" class="px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="min-w-0"><span class="block font-medium text-ink">{{ row.name }}</span><span class="text-xs text-muted">{{ row.branch }}<template v-if="row.website"> → {{ row.website }}</template></span></span>
                        <AcmeBadge :tone="tone[row.decision]" dot>{{ row.label }}</AcmeBadge>
                    </div>
                    <p v-if="!row.pushDeploys" class="mt-1 text-xs text-muted">{{ t('Push deploys are off for this repository, so it only deploys when someone starts it.') }}</p>
                    <p v-if="row.matched.length > 0" class="mt-1 truncate font-mono text-xs text-muted">{{ row.matched.join(', ') }}</p>
                </li>
            </ul>
        </section>
    </UiDialog>
</template>
