<script setup lang="ts">
import type { ServiceBilling } from '~/types/billing';

/** One service's plan: what it is on, its usage this month with pay-as-you-go, and switching tiers. */
const props = defineProps<{ service: ServiceBilling; interval: 'month' | 'year'; canManage: boolean }>();
const { t, number, dateTime } = useT();
const money = useMoney();
const chosen = ref(props.service.options.find((option) => option.current)?.tier.key ?? '');
const busy = ref(false);
const error = ref<string | null>(null);
const price = (tier: ServiceBilling['tier']) => money.cents(props.interval === 'year' ? tier.yearlyCents : tier.monthlyCents);

/** Switch to the chosen tier: off to checkout for a first paid plan, or done here. */
async function change() {
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ redirect: string; message?: string }>('POST', `/account/billing/${props.service.key}`, { tier: chosen.value });
        if (result.redirect.startsWith('http')) {
            window.location.assign(result.redirect);
            return;
        }
        if (result.message) {
            flash(result.message);
        }
        await refreshPage();
    } catch (problem) {
        error.value = problem instanceof ValidationError ? (problem.first('tier') ?? problem.message) : t('Something went wrong. Try again.');
    }
    busy.value = false;
}
</script>

<template>
    <section class="ui-card grid gap-5 p-5 sm:p-6" :aria-labelledby="`billing-${service.key}`">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <span :class="[`product-icon-${service.key === 'monitoring' ? 'monitor' : service.key}`, 'grid h-10 w-10 shrink-0 place-items-center rounded-card']" aria-hidden="true"><Icon :name="service.icon" class="h-5 w-5" /></span>
                <div>
                    <h2 :id="`billing-${service.key}`" class="text-lg font-extrabold text-ink">{{ service.name }}</h2>
                    <p class="text-sm text-muted">
                        {{ service.tier.name }}<template v-if="(service.tier.monthlyCents ?? 0) > 0"> · {{ price(service.tier) }} / {{ interval === 'year' ? t('year') : t('month') }}</template>
                        <template v-if="!service.inUse"> · {{ t('not used by any project yet') }}</template>
                    </p>
                </div>
            </div>
            <div v-if="service.endsAt" class="flex flex-wrap items-center gap-2">
                <Badge tone="warning">{{ t('Moves to free on :date', { date: dateTime(service.endsAt) }) }}</Badge>
                <ApiForm v-if="canManage" :action="`/api/app/account/billing/${service.key}/resume`">
                    <SubmitButton variant="secondary" size="sm">{{ t('Keep :tier', { tier: service.tier.name }) }}</SubmitButton>
                </ApiForm>
            </div>
        </div>

        <div v-for="meter in service.meters" :key="meter.key" class="grid gap-1.5">
            <div class="flex flex-wrap justify-between gap-2 text-sm">
                <span class="font-bold text-ink">{{ t(':meter this month', { meter: meter.name }) }}</span>
                <span class="text-muted">{{ number(meter.used) }} / {{ meter.allowance === null ? t('unlimited') : number(meter.allowance) }} {{ meter.unit }}</span>
            </div>
            <ProgressBar v-if="meter.allowance" :value="Math.min(meter.used, meter.allowance)" :max="meter.allowance" :label="t(':meter used this month', { meter: meter.name })" />
            <div v-if="meter.unitCents > 0 && meter.allowance !== null && (meter.payAsYouGo || meter.payAsYouGoAvailable || canManage)" class="mt-2 grid gap-2 rounded-control border border-line p-3 text-sm">
                <p class="flex items-center gap-2 font-bold text-ink">{{ t('Pay as you go') }} <Badge :tone="meter.payAsYouGo ? 'success' : 'neutral'">{{ meter.payAsYouGo ? t('On') : t('Off') }}</Badge></p>
                <p class="text-muted">
                    {{ t('Past your allowance, :price per :size :unit instead of stopping.', { price: money.cents(meter.unitCents), size: number(meter.unitSize), unit: meter.unit }) }}
                    <template v-if="meter.payAsYouGo">
                        {{ t('So far this month: :cost.', { cost: money.cents(meter.overageCents) }) }}
                        {{ meter.spendCapCents === null ? t('No spend cap.') : t('Stops at :cap a month.', { cap: money.cents(meter.spendCapCents) }) }}
                    </template>
                </p>
                <ApiForm v-if="canManage && (meter.payAsYouGo || meter.payAsYouGoAvailable)" :action="`/api/app/account/billing/${service.key}/usage`" method="PUT" class="!flex flex-wrap items-end !gap-3">
                    <input type="hidden" name="meter" :value="meter.key">
                    <input type="hidden" name="enabled" :value="meter.payAsYouGo ? '0' : '1'">
                    <InputField v-if="!meter.payAsYouGo" :id="`usage-cap-${service.key}`" name="cap" type="number" min="1" max="100000" :label="t('Monthly spend cap in dollars (optional)')" />
                    <SubmitButton :variant="meter.payAsYouGo ? 'quiet' : 'secondary'" size="sm">{{ meter.payAsYouGo ? t('Turn off') : t('Turn on') }}</SubmitButton>
                    <FieldError name="usage" />
                </ApiForm>
                <p v-else-if="canManage" class="text-xs text-muted">{{ t('Available on a paid monthly plan once usage pricing is set up.') }}</p>
            </div>
        </div>

        <form v-if="service.options.length > 1" class="grid gap-4" @submit.prevent="change">
            <fieldset class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" :disabled="!canManage || busy">
                <legend class="sr-only">{{ t(':service plans', { service: service.name }) }}</legend>
                <label
                    v-for="option in service.options"
                    :key="option.tier.key"
                    :class="['flex h-full cursor-pointer flex-col gap-2 rounded-panel border p-4', chosen === option.tier.key ? 'border-primary bg-primary-soft/40' : 'border-line', !option.purchasable && !option.current && 'cursor-not-allowed opacity-60']"
                >
                    <span class="flex items-start justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <input v-model="chosen" type="radio" :name="`tier-${service.key}`" :value="option.tier.key" class="ui-check" :disabled="!option.purchasable && !option.current">
                            <span class="font-extrabold text-ink">{{ option.tier.name }}</span>
                        </span>
                        <span class="text-sm font-bold text-ink">{{ price(option.tier) }}<span v-if="(option.tier.monthlyCents ?? 0) > 0" class="font-normal text-muted">/{{ interval === 'year' ? t('yr') : t('mo') }}</span></span>
                    </span>
                    <span class="text-xs text-muted">{{ option.tier.description }}</span>
                    <ul v-if="option.tier.features.length" class="mt-1 grid gap-1 text-xs text-ink">
                        <li v-for="feature in option.tier.features" :key="feature" class="flex gap-1.5"><span class="text-[var(--ui-success)]" aria-hidden="true">✓</span>{{ feature }}</li>
                    </ul>
                    <Badge v-if="option.current" tone="accent" class="mt-auto w-fit">{{ t('Current plan') }}</Badge>
                    <span v-else-if="!option.purchasable" class="mt-auto text-xs font-bold text-muted">{{ t('Not on sale yet') }}</span>
                </label>
            </fieldset>
            <Alert v-if="error" tone="danger" role="alert">{{ error }}</Alert>
            <div v-if="canManage"><UiButton type="submit" variant="primary" :disabled="busy || service.options.find((option) => option.current)?.tier.key === chosen">{{ busy ? t('Working…') : t('Switch :service plan', { service: service.name }) }}</UiButton></div>
        </form>
    </section>
</template>
