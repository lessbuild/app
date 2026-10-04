<script setup lang="ts">
const value = defineModel<string | number>({ default: '' })
withDefaults(defineProps<{ label: string; type?: string; placeholder?: string; hint?: string; error?: string; hideLabel?: boolean; textarea?: boolean; required?: boolean; icon?: string; autocomplete?: string }>(), { type: 'text' })
const id = useId()
</script>

<template>
  <div>
    <label :for="id" class="mb-1.5 block text-sm font-medium" :class="hideLabel && 'sr-only'">{{ label }}<span v-if="required" class="text-rose-500" aria-hidden="true"> *</span></label>
    <div class="relative">
      <AcmeIcon v-if="icon" :name="icon" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
      <textarea v-if="textarea" :id v-model="value" rows="4" :placeholder :required class="control resize-y" :aria-invalid="!!error || undefined" :aria-describedby="error || hint ? `${id}-desc` : undefined" />
      <input
        v-else :id v-model="value" :type :placeholder :required :autocomplete class="control" :class="[icon && 'pl-9', error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/15']"
        :aria-invalid="!!error || undefined" :aria-describedby="error || hint ? `${id}-desc` : undefined"
      >
    </div>
    <p v-if="error || hint" :id="`${id}-desc`" class="mt-1.5 text-xs" :class="error ? 'text-rose-600 dark:text-rose-400' : 'text-muted'">{{ error || hint }}</p>
  </div>
</template>
