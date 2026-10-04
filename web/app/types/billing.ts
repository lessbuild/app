// The JSON the billing endpoint returns.

export type BillingTier = { key: string; name: string; description: string; features: string[]; monthlyCents: number | null; yearlyCents: number | null };

export type Meter = {
    name: string;
    unit: string;
    used: number;
    allowance: number | null;
    key: string;
    payAsYouGoAvailable: boolean;
    payAsYouGo: boolean;
    spendCapCents: number | null;
    overageCents: number;
    unitSize: number;
    unitCents: number;
};

export type ServiceBilling = {
    key: string;
    name: string;
    icon: string;
    tier: BillingTier;
    endsAt: string | null;
    inUse: boolean;
    options: Array<{ tier: BillingTier; current: boolean; purchasable: boolean }>;
    meters: Meter[];
};

export type Limit = {
    key: string;
    service: string;
    label: string;
    used: number;
    limit: number | null;
    monthly: boolean;
    percent: number;
    nearLimit: boolean;
    upgrade: { tier: string; limit: number | null; monthlyCents: number } | null;
};

export type Billing = {
    account: { id: string; name: string };
    canManage: boolean;
    interval: 'month' | 'year';
    monthlyTotalCents: number;
    currency: string;
    status: string;
    periodEnd: string | null;
    hasCustomer: boolean;
    paymentsAvailable: boolean;
    trialDays: number;
    services: ServiceBilling[];
    limits: Limit[];
    costs: { currency: string; projects: Array<{ name: string; platform: number; cloud: Record<string, number> }>; unassigned: Record<string, number>; unpriced: number; platformTotal: number; cloudTotal: Record<string, number>; billed: Record<string, number> };
    referrals: { link: string; signed_up: number; qualified: number; earned_cents: number; pending_cents: number; credit_cents: number };
    invoices: Array<{ number: string; totalCents: number; currency: string; status: string; date: string; url: string | null }> | null;
};
