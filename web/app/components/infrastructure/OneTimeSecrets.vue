<script setup lang="ts">
/**
 * Passwords the API showed once (a new server's root and MySQL passwords, a website's database password), kept from
 * the form that made them. They're shown until dismissed, and never again.
 */
const props = defineProps<{ labels: Record<string, string>; title: string; description: string }>();
const { t } = useT();
const secrets = useSecrets();
const headingId = useId();
const shown = computed(() => Object.entries(props.labels).filter(([key]) => typeof secrets.value?.[key] === 'string'));
</script>

<template>
    <section v-if="shown.length > 0" class="ui-panel space-y-3 border-warning p-6" :aria-labelledby="headingId">
        <p class="ui-eyebrow">{{ t('Copy them now') }}</p>
        <h2 :id="headingId" class="text-lg font-extrabold text-ink">{{ title }}</h2>
        <p class="text-sm text-muted">{{ description }}</p>
        <div v-for="[key, label] in shown" :key="key">
            <p class="text-xs font-bold text-muted">{{ label }}</p>
            <CodeBlock :code="secrets?.[key] ?? ''" class="whitespace-pre-wrap break-all" />
        </div>
        <UiButton size="sm" @click="secrets = null">{{ t('I’ve copied them') }}</UiButton>
    </section>
</template>
