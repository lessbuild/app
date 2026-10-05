<script setup lang="ts">
import type { Billing } from '~/types/billing';

/**
 * Billing: a plan for each service (only the one you change is affected, and changes are prorated), the monthly or
 * yearly total, limits, costs by project, referrals and invoices, each in its own tab.
 */
definePageMeta({ layout: 'app', area: 'account' });
const { t, tc, number, dateTime } = useT();
const money = useMoney();
const route = useRoute();
const { data } = await useApi<Billing>('/account/billing');
const tabs = computed<Record<string, string>>(() => ({
    overview: t('Overview'),
    ...Object.fromEntries(data.value.services.map((service) => [service.key, service.name])),
    costs: t('Costs by project'),
    invoices: t('Invoices'),
}));
const tab = computed(() => (typeof route.query.tab === 'string' && route.query.tab in tabs.value ? route.query.tab : 'overview'));
const statusLabels = computed<Record<string, string>>(() => ({ none: t('Free'), active: t('Active'), trialing: t('Trial'), past_due: t('Payment overdue'), unpaid: t('Unpaid'), canceled: t('Cancelled'), incomplete: t('Waiting for payment') }));
const portalError = ref<string | null>(null);
const copied = ref(false);

/** Open the payment provider's portal for payment methods and billing details. */
async function portal() {
    try {
        const result = await send<{ redirect: string }>('POST', '/account/billing/portal');
        window.location.assign(result.redirect);
    } catch (problem) {
        portalError.value = problem instanceof ValidationError ? (problem.first('portal') ?? problem.message) : t('Something went wrong. Try again.');
    }
}

async function copyLink() {
    await navigator.clipboard.writeText(data.value.referrals.link).catch(() => null);
    copied.value = true;
}
</script>

<template>
    <SettingsFrame :title="t('Billing')" :description="t('Pick a plan for each service. Only the service you change is affected, and changes are prorated.')" >

        <AcmeAlert v-if="route.query.checkout === 'done'" tone="success" role="status">{{ t('Thanks! Your plan starts as soon as the payment is confirmed; this page updates in a moment.') }}</AcmeAlert>
        <AcmeAlert v-else-if="route.query.checkout === 'cancelled'" tone="info" role="status">{{ t('Checkout was cancelled. Nothing changed.') }}</AcmeAlert>
        <AcmeAlert v-if="portalError" tone="danger" role="alert">{{ portalError }}</AcmeAlert>
        <AcmeAlert v-if="!data.paymentsAvailable" tone="warning" role="status">{{ t('Payments aren’t connected in this environment, so only free plans can be chosen.') }}</AcmeAlert>
        <AcmeAlert v-if="data.trialDays > 0 && data.paymentsAvailable" tone="info" role="status">{{ t('Your first paid plan starts with a :days-day free trial. You won’t be charged until it ends, and you can cancel before then.', { days: data.trialDays }) }}</AcmeAlert>
        <AcmeAlert v-if="data.status === 'past_due' || data.status === 'unpaid'" tone="danger" role="alert">{{ t('Your last payment didn’t go through. Update your payment method to keep your plans.') }}</AcmeAlert>

        <PageTabs :tabs="tabs" :current="tab" :label="t('Billing sections')" />

        <template v-if="tab === 'overview'">
            <AcmeCard :padded="false" :title="t('Plan')" :description="data.periodEnd ? t('Renews :date', { date: dateTime(data.periodEnd) }) : t('Prices exclude tax.')">
                <div class="p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold text-ink">{{ data.interval === 'year' ? t('You pay yearly') : t('You pay monthly') }}</p>
                        <AcmeBadge :tone="data.status === 'past_due' || data.status === 'unpaid' ? 'red' : 'green'" dot>{{ statusLabels[data.status] ?? data.status }}</AcmeBadge>
                    </div>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ money.cents(data.monthlyTotalCents) }} <span class="text-sm font-normal text-muted">/ {{ t('month') }}</span></p>
                    <p class="mt-2 text-xs text-muted">{{ t('Yearly costs ten months, so two months are free. Switching moves every paid plan and is prorated.') }} {{ t('Prices exclude tax.') }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <ApiForm v-if="data.canManage" action="/api/app/account/billing/interval" method="PUT">
                            <input type="hidden" name="interval" :value="data.interval === 'year' ? 'month' : 'year'">
                            <SubmitButton variant="secondary">{{ data.interval === 'year' ? t('Pay monthly instead') : t('Pay yearly, 2 months free') }}</SubmitButton>
                        </ApiForm>
                        <AcmeBtn v-if="data.canManage && data.hasCustomer" @click="portal">{{ t('Payment method and billing details') }}</AcmeBtn>
                    </div>
                </div>
            </AcmeCard>
            <AcmeCard v-if="data.limits.length > 0" :padded="false" :title="t('Plan limits')" :description="t('What you’re using of each limit your plans set. Monthly limits reset on the 1st.')">
                <ul class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6">
                    <li v-for="limit in data.limits" :key="limit.key" class="grid gap-2">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="font-bold text-ink">{{ limit.label.charAt(0).toUpperCase() + limit.label.slice(1) }}<span v-if="limit.monthly" class="font-normal text-muted"> {{ t('this month') }}</span></span>
                            <span :class="['font-semibold', limit.percent >= 100 ? 'text-danger' : limit.nearLimit ? 'text-warning' : 'text-muted']">{{ number(limit.used) }} / {{ number(limit.limit ?? 0) }}</span>
                        </div>
                        <ProgressBar :value="Math.min(limit.used, limit.limit ?? 0)" :max="limit.limit ?? 1" :label="t(':label used', { label: limit.label })" />
                        <NuxtLink v-if="limit.nearLimit && limit.service !== 'account'" :to="{ query: { tab: limit.service } }" class="text-xs font-semibold text-primary hover:underline">
                            {{ limit.percent >= 100 ? t('Limit reached.') : t('Nearly there.') }}
                            {{ limit.upgrade ? t(':tier gives :limit for $:price/mo', { tier: limit.upgrade.tier, limit: limit.upgrade.limit === null ? t('unlimited') : number(limit.upgrade.limit), price: (limit.upgrade.monthlyCents / 100).toFixed(limit.upgrade.monthlyCents % 100 === 0 ? 0 : 2) }) : t('See plans with more') }}
                        </NuxtLink>
                    </li>
                </ul>
            </AcmeCard>
        </template>

        <template v-for="service in data.services" :key="service.key">
            <ServicePlan v-if="tab === service.key" :service="service" :interval="data.interval" :can-manage="data.canManage" />
        </template>

        <template v-if="tab === 'costs'">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="ui-card grid gap-1 p-5">
                    <p class="ui-eyebrow">{{ t(':app a month', { app: 'BuildPusher' }) }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ money.amount(data.costs.platformTotal, data.costs.currency) }}</p>
                    <p class="text-xs text-muted">{{ t('Plans, add-ons and usage past allowances this month') }}</p>
                </div>
                <div class="ui-card grid gap-1 p-5">
                    <p class="ui-eyebrow">{{ t('Servers a month') }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ money.amounts(data.costs.cloudTotal) }}</p>
                    <p class="text-xs text-muted">{{ data.costs.unpriced > 0 ? tc(':count server has no known price|:count servers have no known price', data.costs.unpriced) : t('From your providers’ list prices') }}</p>
                </div>
                <div class="ui-card grid gap-1 p-5">
                    <p class="ui-eyebrow">{{ t('Cloud invoices last month') }}</p>
                    <p class="text-2xl font-semibold text-ink">{{ money.amounts(data.costs.billed) }}</p>
                    <p class="text-xs text-muted">{{ t('What DigitalOcean, Vultr, Linode and AWS billed') }}</p>
                </div>
            </div>
            <AcmeCard id="by-project" :padded="false" :title="t('Costs by project')" :description="t('Each service’s charge is split evenly across the projects that use it, and add-ons across all projects. A server’s cost goes to the projects with websites on it, split evenly when several share it. Servers run in your own cloud accounts, so their cost is billed by your provider, not by us.')">
                <p v-if="data.costs.projects.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No projects yet.') }}</p>
                <DataTable v-else :caption="t('Costs by project')" :framed="false">
                    <template #head><tr><th scope="col">{{ t('Project') }}</th><th scope="col" class="text-right">BuildPusher</th><th scope="col" class="text-right">{{ t('Servers') }}</th></tr></template>
                    <tr v-for="row in data.costs.projects" :key="row.name">
                        <td class="font-bold text-ink">{{ row.name }}</td>
                        <td class="text-right tabular-nums">{{ money.amount(row.platform, data.costs.currency) }}</td>
                        <td class="text-right tabular-nums">{{ money.amounts(row.cloud) }}</td>
                    </tr>
                    <tr v-if="Object.keys(data.costs.unassigned).length > 0">
                        <td class="text-muted">{{ t('Servers no project uses') }}</td>
                        <td class="text-right">—</td>
                        <td class="text-right tabular-nums">{{ money.amounts(data.costs.unassigned) }}</td>
                    </tr>
                </DataTable>
            </AcmeCard>
        </template>

        <template v-if="tab === 'invoices'">
            <AcmeCard id="refer" :padded="false" :title="t('Refer a friend')" :description="t('Share your link. When an account that signs up through it starts paying, you both get :amount of credit off your next invoices.', { amount: money.cents(data.referrals.credit_cents) })">
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <UiField id="referral-link" :label="t('Your link')">
                        <div class="flex min-w-0">
                            <input id="referral-link" class="ui-input min-w-0 flex-1 rounded-r-none" :value="data.referrals.link" readonly>
                            <button type="button" class="ui-btn ui-btn-secondary rounded-l-none border-l-0" @click="copyLink">{{ copied ? t('Copied') : t('Copy') }}</button>
                        </div>
                    </UiField>
                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-muted">{{ t('Signed up') }}</dt><dd class="text-lg font-semibold text-ink">{{ number(data.referrals.signed_up) }}</dd></div>
                        <div><dt class="text-muted">{{ t('Started paying') }}</dt><dd class="text-lg font-semibold text-ink">{{ number(data.referrals.qualified) }}</dd></div>
                        <div>
                            <dt class="text-muted">{{ t('Credit earned') }}</dt>
                            <dd class="text-lg font-semibold text-ink">{{ money.cents(data.referrals.earned_cents) }}</dd>
                            <dd v-if="data.referrals.pending_cents > 0" class="text-xs text-muted">{{ t(':amount more once you have a subscription', { amount: money.cents(data.referrals.pending_cents) }) }}</dd>
                        </div>
                    </dl>
                </div>
            </AcmeCard>
            <AcmeCard :padded="false" :title="t('Invoices')" :description="t('Receipts for past payments.')">
                <p v-if="data.invoices === null" class="p-4 text-sm text-muted sm:p-6">{{ t('Invoices can’t be loaded right now. Try again in a moment.') }}</p>
                <p v-else-if="data.invoices.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No invoices yet.') }}</p>
                <DataTable v-else :caption="t('Invoices')" :framed="false">
                    <template #head><tr><th scope="col">{{ t('Date') }}</th><th scope="col">{{ t('Invoice') }}</th><th scope="col">{{ t('Amount') }}</th><th scope="col">{{ t('Status') }}</th></tr></template>
                    <tr v-for="invoice in data.invoices" :key="invoice.number">
                        <td>{{ dateTime(invoice.date) }}</td>
                        <td><a v-if="invoice.url" class="ui-link" :href="invoice.url" rel="noopener" target="_blank">{{ invoice.number }}</a><template v-else>{{ invoice.number }}</template></td>
                        <td>{{ money.amount(invoice.totalCents / 100, invoice.currency.toUpperCase()) }}</td>
                        <td><AcmeBadge :tone="acmeTone(invoice.status === 'paid' ? 'success' : 'neutral')">{{ invoice.status.charAt(0).toUpperCase() + invoice.status.slice(1) }}</AcmeBadge></td>
                    </tr>
                </DataTable>
            </AcmeCard>
        </template>
    </SettingsFrame>
</template>
