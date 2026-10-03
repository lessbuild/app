<script setup lang="ts">
/**
 * When something happened, as "5 minutes ago" in the person's language, with the full date as its title. The server
 * renders the date (it can't know the browser's clock or time zone); the browser switches to the relative time and
 * keeps it current.
 */
const props = defineProps<{ at: string }>();
const { locale } = useT();
const mounted = ref(false);
const now = ref(Date.now());
let timer: number | undefined;

onMounted(() => {
    mounted.value = true;
    timer = window.setInterval(() => (now.value = Date.now()), 60000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const text = computed(() => {
    void now.value;
    return mounted.value ? ago(props.at, locale.value) : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(props.at));
});
const title = computed(() => (mounted.value ? new Date(props.at).toLocaleString(locale.value) : undefined));
</script>

<template>
    <time :datetime="at" :title="title">{{ text }}</time>
</template>
