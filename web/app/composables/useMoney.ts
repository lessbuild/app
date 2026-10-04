// Prices and amounts, formatted for the person's language.

/** Format amounts: cents in one currency, or amounts in several (such as cloud costs in dollars and euros). */
export function useMoney() {
    const { locale, t } = useT();
    const format = (amount: number, currency: string) => new Intl.NumberFormat(locale.value, { style: 'currency', currency, minimumFractionDigits: Number.isInteger(amount) ? 0 : 2 }).format(amount);

    return {
        /** A price in cents, such as "$19"; a price not set yet says so. */
        cents: (cents: number | null, currency = 'USD') => (cents === null ? t('Price to be set') : format(cents / 100, currency)),
        /** An amount in a currency, such as "€8.00". */
        amount: (value: number, currency: string) => new Intl.NumberFormat(locale.value, { style: 'currency', currency }).format(value),
        /** Amounts in several currencies, such as "€8.00 + $34.00"; none is a dash. */
        amounts: (values: Record<string, number>) => {
            const entries = Object.entries(values);
            return entries.length === 0 ? '—' : entries.map(([currency, value]) => new Intl.NumberFormat(locale.value, { style: 'currency', currency }).format(value)).join(' + ');
        },
    };
}
