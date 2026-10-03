/** Before the first page renders, use the language the browser prefers; signed-in pages switch to the person's own. */
export default defineNuxtPlugin(async () => {
    await setLocale(preferredLocale(useRequestHeaders(['accept-language'])['accept-language']));
});
