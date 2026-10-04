<script setup lang="ts">
import type { SiteSettings } from '~/types/analytics';

/** A site's settings fields, for adding a site and for its settings: the essentials first, the rest folded away. */
const props = defineProps<{ id: string; timezones: string[]; site?: SiteSettings | null }>();
const { t } = useT();
const zones = computed(() => props.timezones.map((zone) => ({ value: zone, label: zone })));
const zone = ref(props.site?.timezone ?? 'UTC');
onMounted(() => {
    // A new site reports in the browser's time zone unless changed (set after hydration, which renders UTC).
    const guessed = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!props.site && props.timezones.includes(guessed)) {
        zone.value = guessed;
    }
});
const lines = (values: string[] | undefined) => (values ?? []).join('\n');
</script>

<template>
    <div class="grid gap-4">
        <InputField :id="`${id}-name`" name="name" :label="t('Site name')" :model-value="site?.name ?? ''" maxlength="100" required autofocus />
        <TextareaField :id="`${id}-domains`" name="domains" :label="t('Hostnames')" :model-value="lines(site?.domains)" rows="2" required :description="t('One per line, such as example.com and www.example.com. The tracker only accepts data sent from these.')" />
        <SelectField :id="`${id}-timezone`" v-model="zone" name="timezone" :label="t('Report time zone')" :options="zones" required />
        <Disclosure :title="t('More options')">
            <div class="grid gap-4">
                <TextareaField :id="`${id}-excluded-paths`" name="excluded_paths" :label="t('Paths to ignore')" :model-value="lines(site?.excludedPaths)" rows="2" :description="t('Optional. One pattern per line; * matches anything, e.g. /admin/*.')" />
                <InputField :id="`${id}-properties`" name="custom_properties" :label="t('Custom properties to keep')" :model-value="(site?.customProperties ?? []).join(', ')" maxlength="500" placeholder="plan, author, logged_in" :description="t('Optional. Up to 10 property names sent with custom events, for breakdowns. Others are dropped, and values that look like email addresses are never kept, so don’t send personal data.')" />
                <TextareaField :id="`${id}-ips`" name="excluded_ips" :label="t('Addresses to ignore')" :model-value="lines(site?.excludedIps)" rows="2" :description="t('Optional. Visits from these addresses or networks (such as 203.0.113.0/24) aren’t counted, like your office.')" />
                <TextareaField :id="`${id}-groups`" name="content_groups" :label="t('Content groups')" :model-value="(site?.contentGroups ?? []).map((group) => `${group.name}: ${group.pattern}`).join('\n')" rows="3" placeholder="Blog: /blog/*" :description="t('Optional. One group per line, as a name and a path pattern; * matches anything. Reports add up each group’s pages.')" />
                <TextareaField :id="`${id}-referrers`" name="blocked_referrers" :label="t('Referrers to treat as spam')" :model-value="lines(site?.blockedReferrers)" rows="2" :description="t('Optional. Domains whose visits are dropped, besides the known referrer-spam list.')" />
            </div>
        </Disclosure>
    </div>
</template>
