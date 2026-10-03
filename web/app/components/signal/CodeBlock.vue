<script setup lang="ts">
/** Signal's code block. */
defineProps<{ code: string }>();
</script>

<template>
    <pre class="ui-code-block"><code>{{ code }}</code></pre>
</template>
