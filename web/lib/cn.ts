/** Join class names, skipping empty ones, like Blade's @class. */
export function cn(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}
