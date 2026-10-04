<script setup lang="ts">
import { NuxtLink } from '#components'

// A row in a ListGroup. With `to` the whole row is a link.
defineProps<{ to?: string; title?: string; meta?: string; avatar?: string; icon?: string }>()
</script>

<template>
  <li>
    <component :is="to ? NuxtLink : 'div'" :to class="flex items-center gap-3 px-4 py-3 text-sm sm:px-5" :class="to && 'transition-colors hover:bg-black/[.02] dark:hover:bg-white/[.03]'">
      <AcmeAvatar v-if="avatar" :name="avatar" size="sm" />
      <AcmeIconBubble v-else-if="icon" :icon />
      <slot name="leading" />
      <div class="min-w-0 flex-1">
        <slot>
          <p class="truncate font-medium">{{ title }}</p>
          <p v-if="meta" class="truncate text-muted">{{ meta }}</p>
        </slot>
      </div>
      <slot name="trailing" />
    </component>
  </li>
</template>
