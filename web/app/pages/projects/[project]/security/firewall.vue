<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** Cloudflare's protection for the zones behind the project's domains: security level, bot fight mode and under attack mode. */
definePageMeta({ layout: 'app', service: 'security' });
type Zone = { id: string; name: string | null; domains: string[]; level: string; botFightMode: boolean; underAttack: boolean; error: string | null };
type FirewallPage = { overview: ProjectOverview; zones: Zone[]; levels: Option[]; included: boolean; canManage: boolean };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<FirewallPage>(() => `/projects/${route.params.project}/security/firewall`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/security/firewall`);
const levelLabel = (level: string) => data.value.levels.find((option) => option.value === level)?.label ?? level;
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('Cloudflare’s protection for the zones behind this project’s domains. Per-domain country and address blocks and rate limits are on each website’s Domains tab.')" />
        <PlanNotice v-if="!data.included" :message="t('Firewall and bot controls come with the Team Security plan.')" />

        <EmptyState v-if="data.zones.length === 0" icon="globe" :title="t('No Cloudflare zones yet')" :description="t('Connect Cloudflare as a DNS provider and add a website domain in one of its zones; the zone shows up here.')" />
        <section v-for="zone in data.zones" :key="zone.id" class="ui-card grid gap-4 p-5 sm:p-6" :aria-labelledby="`zone-${zone.id}`">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 :id="`zone-${zone.id}`" class="flex flex-wrap items-center gap-2 text-lg font-semibold text-ink">
                        {{ zone.name ?? t('Cloudflare zone') }}
                        <AcmeBadge v-if="zone.underAttack" tone="red">{{ t('Under attack mode') }}</AcmeBadge>
                    </h2>
                    <p class="text-sm text-muted">{{ zone.domains.join(', ') }}</p>
                </div>
                <FormDialog v-if="data.canManage && data.included" :id="`zone-${zone.id}-edit`" :title="t('Protection for :zone', { zone: zone.name ?? t('Cloudflare zone') })" :action="`${base}/${zone.id}`" method="PUT" :submit="t('Save')">
                    <template #trigger="{ open }"><AcmeBtn variant="secondary" size="sm" @click="open">{{ t('Change') }}</AcmeBtn></template>
                    <SelectField :id="`zone-${zone.id}-level`" name="security_level" :label="t('Security level')" :description="t('How suspicious a visitor must look before Cloudflare asks them to prove they’re human.')" :options="data.levels" :model-value="zone.level" />
                    <CheckboxField :id="`zone-${zone.id}-bots`" name="bot_fight_mode" :label="t('Bot fight mode')" :description="t('Challenge traffic from known bots and scrapers.')" :checked="zone.botFightMode" />
                    <CheckboxField :id="`zone-${zone.id}-attack`" name="under_attack" :label="t('Under attack mode')" :description="t('Every visitor gets a short check before reaching the site. Use it only during an attack.')" :checked="zone.underAttack" />
                </FormDialog>
            </div>
            <AcmeAlert v-if="zone.error" tone="danger">{{ zone.error }}</AcmeAlert>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs font-bold text-muted">{{ t('Security level') }}</dt><dd class="mt-1 font-semibold text-ink">{{ levelLabel(zone.level) }}</dd></div>
                <div><dt class="text-xs font-bold text-muted">{{ t('Bot fight mode') }}</dt><dd class="mt-1 font-semibold text-ink">{{ zone.botFightMode ? t('On') : t('Off') }}</dd></div>
                <div><dt class="text-xs font-bold text-muted">{{ t('Under attack mode') }}</dt><dd class="mt-1 font-semibold text-ink">{{ zone.underAttack ? t('On') : t('Off') }}</dd></div>
            </dl>
        </section>
    </div>
</template>
