// File and disk sizes, formatted for the person's language.

/** Format a number of bytes, such as "3 GB" or "120 B" (binary steps, as Laravel's Number::fileSize). */
export function useFileSize() {
    const { locale } = useT();
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    return (bytes: number | null) => {
        if (bytes === null) {
            return '—';
        }
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: unit === 0 ? 0 : 1 }).format(value)} ${units[unit]}`;
    };
}
