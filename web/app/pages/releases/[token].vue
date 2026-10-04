<script setup lang="ts">
/** An environment's published release notes, for its users: no sign-in, no names, grouped notes per release. */
type PublicReleaseNotes = { project: string; environment: string; releases: Array<{ id: number; name: string | null; activatedAt: string | null; sections: Record<string, string[]> }> };
const { t, date } = useT();
const route = useRoute();
const { data } = await useApi<PublicReleaseNotes>(() => `/releases/${route.params.token}`);
const releases = computed(() => data.value.releases.filter((release) => Object.keys(release.sections).length > 0));
useHead({
    title: () => t(':project release notes', { project: data.value.project }),
    meta: [{ name: 'description', content: () => t('What changed in each release of :project.', { project: data.value.project }) }, { name: 'robots', content: 'noindex' }],
});
</script>

<template>
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-3xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow">{{ data.project }}</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ t('Release notes') }}</h1>
        </header>
        <article v-for="release in releases" :key="release.id" class="ui-card grid gap-3 p-5 sm:p-6">
            <h2 class="font-extrabold text-ink"><time v-if="release.activatedAt" :datetime="release.activatedAt">{{ date(release.activatedAt) }}</time></h2>
            <ReleaseNotes :sections="release.sections" />
        </article>
        <p v-if="releases.length === 0" class="text-sm text-muted">{{ t('No releases yet.') }}</p>
    </main>
</template>
