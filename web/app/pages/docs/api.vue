<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/** The public API's operations by group, from its OpenAPI description. */
definePageMeta({ layout: 'public' });
type ApiPage = { meta: PageMeta; description: string; groups: Array<{ tag: string; operations: Array<{ method: string; path: string; summary: string; scopes: string[] }> }>; openApiUrl: string };
const { t } = useT();
const { data } = await useApi<ApiPage>('/docs/api');
usePublicPage(() => data.value.meta);
</script>

<template>
    <div>
        <SiteHero kicker="BuildPusher" :title="t('API reference')" :description="data.description">
            <p class="mt-5 text-sm text-muted"><a :href="data.openApiUrl" class="font-medium text-primary underline">{{ t('OpenAPI description (JSON)') }}</a> · {{ t('Create tokens under Account → API tokens.') }}</p>
        </SiteHero>
        <SiteSection frame-class="grid gap-10">
            <section v-for="group in data.groups" :key="group.tag" class="grid gap-3" :aria-labelledby="`api-${group.tag}`">
                <h2 :id="`api-${group.tag}`" class="text-lg font-semibold text-ink">{{ group.tag }}</h2>
                <DataTable :caption="group.tag">
                    <template #head><tr><th scope="col">{{ t('Request') }}</th><th scope="col">{{ t('What it does') }}</th><th scope="col">{{ t('Scope') }}</th></tr></template>
                    <tr v-for="operation in group.operations" :key="`${operation.method}-${operation.path}`">
                        <td class="whitespace-nowrap font-mono text-xs"><span class="font-semibold">{{ operation.method }}</span> {{ operation.path }}</td>
                        <td>{{ operation.summary }}</td>
                        <td class="font-mono text-xs">{{ operation.scopes.join(', ') || '—' }}</td>
                    </tr>
                </DataTable>
            </section>
        </SiteSection>
    </div>
</template>
