import type { InjectionKey } from 'vue';

// The state an ApiForm shares with the fields inside it.

export type FormState = { errors: Record<string, string[]>; busy: boolean };

export const formKey: InjectionKey<FormState> = Symbol('form');

/** The errors and busy state of the form a field is in (none outside a form). */
export function useForm(): FormState {
    return inject(formKey, reactive({ errors: {}, busy: false }));
}

/** Laravel's error key for an input name: `services[]` → `services`, `keys[p256dh]` → `keys.p256dh`. */
export function errorKey(name: string): string {
    return name.replace(/\[\]$/, '').replace(/\[([^\]]*)\]/g, '.$1');
}

/** A field's error and the ids that describe its input, for aria-describedby. */
export function useFieldState(id: () => string, name: () => string, hasDescription: () => boolean, key?: () => string | undefined) {
    const form = useForm();
    const field = computed(() => key?.() ?? errorKey(name()));
    const error = computed(() => firstError(form.errors, field.value));
    const describedBy = computed(() => [hasDescription() ? `${id()}-help` : null, error.value ? `${id()}-error` : null].filter(Boolean).join(' ') || undefined);

    return { field, error, describedBy };
}
