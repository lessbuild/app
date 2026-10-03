/** Turn one of Laravel's absolute URLs on this site into a path, so links stay inside the app. */
export function local(url: string): string {
    try {
        const parsed = new URL(url);
        return parsed.pathname + parsed.search + parsed.hash;
    } catch {
        return url;
    }
}
