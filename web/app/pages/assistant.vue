<script setup lang="ts">
/**
 * The assistant (the Acme theme's Ask page): ask about the account's deploys, errors, checks, incidents, Analytics and
 * servers in plain language, starting from a suggestion or your own question, follow up, come back to recent
 * conversations, or use it from your own AI tools over MCP. While an answer is being worked out, the page checks every
 * few seconds.
 */
definePageMeta({ layout: 'app' });
type AssistantPage = {
    accountName: string;
    conversation: { id: string; status: 'thinking' | 'answered' | 'failed'; error: string | null; lines: Array<{ role: 'user' | 'assistant'; text: string; html: string | null }> } | null;
    conversations: Array<{ id: string; title: string }>;
    configured: boolean;
    mcpUrl: string;
};
const { t } = useT();
const route = useRoute();
const { data } = await useApi<AssistantPage>('/assistant', () => ({ conversation: typeof route.query.conversation === 'string' ? route.query.conversation : undefined }));
const question = ref('');
const suggestions = computed(() => [
    t('Why did errors go up after the last deploy?'),
    t('Which monitors were down this week?'),
    t('What changed before the last incident?'),
    t('Which pages got the most visitors yesterday?'),
]);
const thinking = computed(() => data.value.conversation?.status === 'thinking');
let timer: ReturnType<typeof setInterval> | undefined;
watch(thinking, (now) => {
    clearInterval(timer);
    if (now && import.meta.client) {
        timer = setInterval(() => refreshPage(), 3000);
    }
}, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));
useHead({ title: () => t('Ask') });
</script>

<template>
    <div class="space-y-6">
        <PageHeader :eyebrow="data.accountName" :title="t('Ask')" :description="t('Ask about your deploys, errors, uptime checks, incidents, Analytics and servers in plain language, like “why did errors go up after the last deploy?”. Answers only use what you can see.')">
            <template v-if="data.conversation" #actions><AcmeBtn icon="plus" to="/assistant">{{ t('New conversation') }}</AcmeBtn></template>
        </PageHeader>
        <AcmeAlert v-if="!data.configured" tone="info">{{ t('The assistant isn’t set up on this installation yet. You can still ask from your own AI tools; see Use it from your AI tools.') }}</AcmeAlert>
        <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
            <section class="flex min-h-[32rem] flex-col rounded-2xl border border-line bg-surface shadow-card">
                <div class="flex-1 space-y-5 p-5 sm:p-6" aria-live="polite">
                    <div v-if="!data.conversation" class="grid h-full place-items-center py-16 text-center">
                        <div>
                            <AcmeIconBubble icon="sparkle" class="mx-auto" />
                            <p class="mt-3 font-medium text-ink">{{ t('What do you want to know?') }}</p>
                            <div v-if="data.configured" class="mt-4 flex flex-wrap justify-center gap-2">
                                <button v-for="suggestion in suggestions" :key="suggestion" type="button" class="rounded-full border border-line px-3 py-1.5 text-sm text-ink hover:bg-black/[.03] dark:hover:bg-white/[.05]" @click="question = suggestion">{{ suggestion }}</button>
                            </div>
                        </div>
                    </div>
                    <template v-else>
                        <div v-for="(line, index) in data.conversation.lines" :key="index" :class="['flex gap-3', line.role === 'user' && 'justify-end']">
                            <span v-if="line.role === 'assistant'" class="grid size-8 shrink-0 place-items-center rounded-full bg-violet-500/10 text-violet-600" aria-hidden="true"><AcmeIcon name="sparkle" :size="15" /></span>
                            <div :class="['max-w-[42rem] rounded-2xl px-4 py-3 text-sm leading-6', line.role === 'user' ? 'bg-ink text-canvas' : 'border border-line bg-surface text-ink']">
                                <span class="sr-only">{{ line.role === 'user' ? t('You') : t('Assistant') }}:</span>
                                <p v-if="line.role === 'user'" class="whitespace-pre-wrap">{{ line.text }}</p>
                                <!-- eslint-disable-next-line vue/no-v-html -- Markdown rendered by Laravel with raw HTML and unsafe links stripped. -->
                                <div v-else class="ui-prose" v-html="line.html" />
                            </div>
                        </div>
                        <p v-if="thinking" class="flex items-center gap-2 text-sm text-muted" role="status"><AcmeIcon name="sparkle" :size="14" class="animate-pulse text-violet-500" />{{ t('Looking into it…') }}</p>
                        <AcmeAlert v-else-if="data.conversation.status === 'failed'" tone="danger">{{ data.conversation.error }}</AcmeAlert>
                    </template>
                </div>
                <ApiForm v-if="data.configured" action="/api/app/assistant" class="flex items-end gap-2 border-t border-line p-4">
                    <input v-if="data.conversation" type="hidden" name="conversation" :value="data.conversation.id">
                    <TextareaField id="question" :key="data.conversation?.id ?? 'new'" v-model="question" name="question" :label="data.conversation ? t('Ask a follow-up') : t('Your question')" hide-label rows="2" :placeholder="data.conversation ? t('Ask a follow-up…') : t('Ask anything about your projects…')" class="flex-1" required />
                    <SubmitButton :disabled="thinking">{{ t('Ask') }}</SubmitButton>
                </ApiForm>
            </section>
            <div class="space-y-6">
                <AcmeCard v-if="data.conversations.length > 0" :title="t('Recent conversations')" :description="t('Kept for 30 days.')">
                    <ul class="space-y-1 text-sm">
                        <li v-for="recent in data.conversations" :key="recent.id">
                            <NuxtLink :to="`/assistant?conversation=${recent.id}`" :class="['block truncate rounded-lg px-2 py-1.5 hover:bg-black/[.03] dark:hover:bg-white/[.05]', data.conversation?.id === recent.id ? 'bg-black/[.04] font-medium text-ink' : 'text-ink']" :aria-current="data.conversation?.id === recent.id ? 'page' : undefined">{{ recent.title }}</NuxtLink>
                        </li>
                    </ul>
                </AcmeCard>
                <AcmeCard :title="t('Use it from your AI tools')" :description="t('Add this MCP server to Claude, Cursor or another AI tool, with an API token from Account → API tokens as the bearer token. The tools see what the token’s scopes allow.')">
                    <CodeBlock :code="data.mcpUrl" class="break-all whitespace-pre-wrap" />
                    <AcmeBtn size="sm" variant="ghost" class="mt-3" to="/account/api-tokens">{{ t('Create an API token') }}</AcmeBtn>
                </AcmeCard>
            </div>
        </div>
    </div>
</template>
