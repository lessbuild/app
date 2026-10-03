<script setup lang="ts">
/**
 * A form that posts to the API (`/api/app/…`) as form data: field names as Laravel expects them, method spoofing for
 * PUT and DELETE, files included. Laravel's validation errors appear on their fields. On success the page's data
 * loads again, or the app goes where the API's `{ redirect }` says; a download is saved.
 */
const props = withDefaults(defineProps<{
    action: string;
    method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    /** Ask this before sending. */
    confirm?: string;
    /**
     * Decide where to go after a successful submit, from the API's answer: a path loads that page in full (as after
     * signing in), `null` stays put without loading anything. Without it, the page's data loads again or the app
     * follows `{ redirect }`.
     */
    after?: (data: Record<string, unknown>) => string | null | undefined;
}>(), { method: 'POST', confirm: undefined, after: undefined });
const emit = defineEmits<{ success: [redirect: string | null] }>();
const { t } = useT();
const route = useRoute();
const form = ref<HTMLFormElement | null>(null);
const state = reactive<FormState>({ errors: {}, busy: false });
provide(formKey, state);

/** Show errors and move focus to the first one. */
function fail(errors: Record<string, string[]>) {
    state.errors = errors;
    state.busy = false;
    nextTick(() => form.value?.querySelector<HTMLElement>('[aria-invalid="true"], [data-form-error]')?.focus());
}

async function submit(event: SubmitEvent) {
    if (state.busy || (props.confirm && !window.confirm(props.confirm))) {
        return;
    }
    const body = new FormData(event.currentTarget as HTMLFormElement);
    if (props.method !== 'POST') {
        body.append('_method', props.method);
    }
    state.errors = {};
    state.busy = true;
    await ensureCsrf();
    const post = () => fetch(props.action, { method: 'POST', body, credentials: 'same-origin', headers: writeHeaders() });
    let response: Response;
    try {
        response = await post();
        if (response.status === 423) {
            // A sensitive change: confirm it's them, then send the form again.
            if (!(await confirmIdentity())) {
                state.busy = false;
                return;
            }
            response = await post();
        }
    } catch {
        fail({ _form: [t('The connection failed. Check you’re online and try again.')] });
        return;
    }

    if (response.status === 422) {
        const payload = (await response.json()) as { errors?: Record<string, string[]>; message?: string };
        fail(payload.errors ?? { _form: [payload.message ?? t('Check the form and try again.')] });
        return;
    }
    if (response.status === 409) {
        // A check sends the person somewhere first (such as setting up a second factor).
        window.location.assign(((await response.json()) as { redirect: string }).redirect);
        return;
    }
    if (response.status === 419) {
        // The session expired while the form was open: reload to get a fresh one.
        window.location.reload();
        return;
    }
    if (!response.ok) {
        const payload = (await response.json().catch(() => null)) as { message?: string } | null;
        fail({ _form: [payload?.message || t('Something went wrong. Try again.')] });
        return;
    }

    if (!(response.headers.get('content-type') ?? '').includes('application/json')) {
        // A download, such as an export: save it.
        const blob = await response.blob();
        const name = /filename="?([^";]+)"?/.exec(response.headers.get('content-disposition') ?? '')?.[1] ?? 'download';
        const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
        link.click();
        URL.revokeObjectURL(link.href);
        state.busy = false;
        return;
    }

    const text = await response.text();
    const payload = (text ? JSON.parse(text) : {}) as { redirect?: string } & Record<string, unknown>;
    if (typeof payload.message === 'string') {
        flash(payload.message);
    }
    if (props.after) {
        const next = props.after(payload);
        if (typeof next === 'string') {
            window.location.assign(next);
            return;
        }
        if (next === null) {
            state.busy = false;
            return;
        }
    }
    const target = payload.redirect ? local(payload.redirect) : null;
    emit('success', target);
    if (target !== null && target !== route.fullPath) {
        await navigateTo(target);
    }
    // Staying on the same page (perhaps without a dialog's query): its data is keyed by path, so load it again.
    if (target === null || target.split('?')[0] === route.path) {
        await refreshPage();
    }
    state.busy = false;
}
</script>

<template>
    <form ref="form" class="grid gap-5" :aria-busy="state.busy || undefined" @submit.prevent="submit">
        <div v-if="state.errors._form" data-form-error tabindex="-1" role="alert" class="ui-alert ui-alert--danger ui-alert-danger">{{ state.errors._form[0] }}</div>
        <slot />
    </form>
</template>
