// The JSON the providers endpoints return.

export type ProviderSummary = {
    id: number;
    name: string;
    type: string;
    typeLabel: string;
    purpose: string;
    hostsServers: boolean;
    serverCount: number;
    status: string;
    checkedAt: string | null;
};

export type ProviderType = { value: string; label: string; purpose: string };
