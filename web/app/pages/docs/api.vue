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
    <div class="mx-auto grid max-w-5xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow">BuildPusher</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ t('API reference') }}</h1>
            <p class="mt-3 max-w-3xl whitespace-pre-line text-sm leading-6 text-muted">{{ data.description }}</p>
            <p class="mt-3 text-sm"><a :href="data.openApiUrl" class="font-bold text-primary underline">{{ t('OpenAPI description (JSON)') }}</a> · {{ t('Create tokens under Account → API tokens.') }}</p>
        </header>
        <section v-for="group in data.groups" :key="group.tag" class="grid gap-3" :aria-labelledby="`api-${group.tag}`">
            <h2 :id="`api-${group.tag}`" class="text-lg font-extrabold text-ink">{{ group.tag }}</h2>
            <DataTable :caption="group.tag">
            <template #head><tr><th scope="col">{{ t('Request') }}</th><th scope="col">{{ t('What it does') }}</th><th scope="col">{{ t('Scope') }}</th></tr></template>
            <tr v-for="operation in group.operations" :key="`${operation.method}-${operation.path}`">
                <td class="whitespace-nowrap font-mono text-xs"><span class="font-bold">{{ operation.method }}</span> {{ operation.path }}</td>
                <td>{{ operation.summary }}</td>
                <td class="font-mono text-xs">{{ operation.scopes.join(', ') || '—' }}</td>
            </tr>
            </DataTable>
        </section>
    </div>
</template>
