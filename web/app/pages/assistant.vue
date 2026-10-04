<script setup lang="ts">
/**
 * The assistant: ask about the account's deploys, errors, checks, incidents, Analytics and servers in plain language,
 * follow up, come back to recent conversations, or use it from your own AI tools over MCP. While an answer is being
 * worked out, the page checks every few seconds.
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
        <PageHeader :eyebrow="data.accountName" :title="t('Ask')" :description="t('Ask about your deploys, errors, uptime checks, incidents, Analytics and servers in plain language, like “why did errors go up after the last deploy?”. Answers only use what you can see.')" />
        <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
            <div class="grid content-start gap-4">
                <Alert v-if="!data.configured" tone="info">{{ t('The assistant isn’t set up on this installation yet. You can still ask from your own AI tools; see Use it from your AI tools.') }}</Alert>
                <section v-if="data.conversation" class="ui-panel grid gap-4 p-4 sm:p-6" aria-live="polite">
                    <div v-for="(line, index) in data.conversation.lines" :key="index" :class="['rounded-card p-3', line.role === 'user' && 'bg-primary-soft']">
                        <p class="ui-eyebrow">{{ line.role === 'user' ? t('You') : t('Assistant') }}</p>
                        <p v-if="line.role === 'user'" class="mt-1 whitespace-pre-wrap text-sm text-ink">{{ line.text }}</p>
                        <!-- eslint-disable-next-line vue/no-v-html -- Markdown rendered by Laravel with raw HTML and unsafe links stripped. -->
                        <div v-else class="ui-prose mt-1 text-sm text-ink" v-html="line.html" />
                    </div>
                    <p v-if="thinking" class="text-sm text-muted" role="status">{{ t('Looking into it…') }}</p>
                    <Alert v-else-if="data.conversation.status === 'failed'" tone="danger">{{ data.conversation.error }}</Alert>
                </section>
                <ApiForm v-if="data.configured" action="/api/app/assistant" class="grid gap-3">
                    <input v-if="data.conversation" type="hidden" name="conversation" :value="data.conversation.id">
                    <TextareaField id="question" :key="data.conversation?.id ?? 'new'" name="question" :label="data.conversation ? t('Ask a follow-up') : t('Your question')" rows="3" required />
                    <div class="flex flex-wrap gap-3">
                        <SubmitButton :disabled="thinking">{{ t('Ask') }}</SubmitButton>
                        <UiButton v-if="data.conversation" to="/assistant" variant="quiet">{{ t('New conversation') }}</UiButton>
                    </div>
                </ApiForm>
            </div>
            <div class="grid content-start gap-4">
                <nav v-if="data.conversations.length > 0" class="ui-panel grid gap-1 p-4" :aria-label="t('Recent conversations')">
                    <p class="ui-eyebrow">{{ t('Recent conversations') }}</p>
                    <NuxtLink v-for="recent in data.conversations" :key="recent.id" :to="`/assistant?conversation=${recent.id}`" class="truncate text-sm text-ink hover:underline" :aria-current="data.conversation?.id === recent.id ? 'page' : undefined">{{ recent.title }}</NuxtLink>
                    <p class="mt-2 text-xs text-muted">{{ t('Kept for 30 days.') }}</p>
                </nav>
                <section class="ui-panel grid gap-2 p-4" aria-labelledby="mcp-heading">
                    <h2 id="mcp-heading" class="text-sm font-bold text-ink">{{ t('Use it from your AI tools') }}</h2>
                    <p class="text-xs text-muted">{{ t('Add this MCP server to Claude, Cursor or another AI tool, with an API token from Account → API tokens as the bearer token. The tools see what the token’s scopes allow.') }}</p>
                    <CodeBlock :code="data.mcpUrl" class="break-all whitespace-pre-wrap" />
                </section>
            </div>
        </div>
    </div>
</template>
