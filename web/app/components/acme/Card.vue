<script setup lang="ts">
// Surface card. `link` adds a small "View all"-style link in the header; `interactive` lifts on hover.
withDefaults(defineProps<{ title?: string; description?: string; padded?: boolean; link?: { label: string; to: string }; icon?: string; interactive?: boolean }>(), { padded: true })
</script>

<template>
  <section class="rounded-2xl border border-line bg-surface shadow-card" :class="[padded && 'p-5 sm:p-6', interactive && 'transition hover:-translate-y-0.5 hover:shadow-lift']">
    <div v-if="title || link || $slots.action" class="flex items-start justify-between gap-4" :class="padded ? 'mb-5' : 'p-5 pb-4 sm:p-6 sm:pb-4'">
      <div class="flex min-w-0 items-center gap-3">
        <span v-if="icon" class="grid size-9 shrink-0 place-items-center rounded-xl bg-black/[.04] text-ink/70 dark:bg-white/[.06]"><AcmeIcon :name="icon" :size="18" /></span>
        <div class="min-w-0">
          <h2 class="font-semibold tracking-tight">{{ title }}</h2>
          <p v-if="description" class="mt-0.5 text-sm text-muted">{{ description }}</p>
        </div>
      </div>
      <slot name="action"><NuxtLink v-if="link" :to="link.to" class="flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-muted transition-colors hover:bg-black/[.04] hover:text-ink dark:hover:bg-white/[.06]">{{ link.label }}<AcmeIcon name="chevronRight" :size="14" /></NuxtLink></slot>
    </div>
    <slot />
  </section>
</template>
