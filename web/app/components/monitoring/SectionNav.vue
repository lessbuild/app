<script setup lang="ts">
/**
 * The pages that share a Monitoring section: the alert pages (rules, destinations, on-call, maintenance, noise) or the
 * metric pages (explorer, dashboards).
 */
const props = defineProps<{ section: 'alerts' | 'metrics'; projectId: string }>();
const { t } = useT();
const route = useRoute();
const links = computed(() => {
    const base = `/projects/${props.projectId}/monitoring`;
    const items = props.section === 'alerts'
        ? [
            { to: `${base}/rules`, label: t('Rules') },
            { to: `${base}/alerts`, label: t('Destinations'), exact: true },
            { to: `${base}/on-call`, label: t('On-call') },
            { to: `${base}/maintenance`, label: t('Maintenance') },
            { to: `${base}/alerts/noise`, label: t('Noise') },
        ]
        : [{ to: `${base}/metrics`, label: t('Explorer') }, { to: `${base}/dashboards`, label: t('Dashboards') }];
    return items.map((item) => ({
        ...item,
        current: item.to === `${base}/alerts`
            ? route.path === item.to || (/\/alerts\/\d+$/.test(route.path))
            : route.path === item.to || route.path.startsWith(`${item.to}/`),
    }));
});
</script>

<template>
    <nav class="ui-local-nav" :aria-label="section === 'alerts' ? t('Alerts') : t('Metrics')">
        <div class="ui-local-nav__scroll">
            <NuxtLink v-for="link in links" :key="link.to" :to="link.to" class="ui-local-nav__link" :aria-current="link.current ? 'page' : undefined">{{ link.label }}</NuxtLink>
        </div>
    </nav>
</template>
