import { icons } from './icons';

/** A Signal icon, drawn inline from the same paths the Blade pages use. Decorative: give the control its own label. */
export function Icon({ name, className = 'h-5 w-5' }: { name: string; className?: string }) {
    const icon = icons[name] ?? icons['information-circle'];
    if (!icon) {
        return null;
    }

    return (
        <svg
            className={className}
            viewBox={icon.viewBox}
            fill="none"
            stroke={icon.stroke ? 'currentColor' : undefined}
            strokeWidth={icon.stroke ? 1.8 : undefined}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            dangerouslySetInnerHTML={{ __html: icon.body }}
        />
    );
}
