/**
 * Remember the browser's time zone in a cookie, so pages rendered on the server show dates and times in it (and
 * match what the browser renders).
 */
export default defineNuxtPlugin(() => {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const cookie = useCookie<string | undefined>('bp_tz', { maxAge: 60 * 60 * 24 * 365, sameSite: 'lax', path: '/' });
    if (zone && cookie.value !== zone) {
        cookie.value = zone;
    }
});
