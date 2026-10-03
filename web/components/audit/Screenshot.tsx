import type { Box } from '@/lib/types';

/**
 * A screenshot from an audit with the elements in question outlined. Boxes are in screen pixels, so they're placed as
 * percentages of the screen size and stay on their elements at any width.
 */
export function Screenshot({ src, alt, boxes = [], screen }: { src: string; alt: string; boxes?: Box[]; screen: { width: number; height: number } }) {
    return (
        <figure className="relative overflow-hidden rounded-card border border-line bg-surface-muted">
            {/* eslint-disable-next-line @next/next/no-img-element -- private images behind the session; Next's optimiser can't fetch them. */}
            <img src={src} alt={alt} width={screen.width} height={screen.height} loading="lazy" className="block h-auto w-full" />
            {boxes.map((box, index) => (
                <span
                    key={index}
                    aria-hidden="true"
                    className="absolute rounded-[3px] bg-[var(--ui-danger)]/10 outline outline-[3px] outline-offset-2 outline-[var(--ui-danger)]"
                    style={{
                        left: `${(box.x / screen.width) * 100}%`,
                        top: `${(box.y / screen.height) * 100}%`,
                        width: `${(box.width / screen.width) * 100}%`,
                        height: `${(box.height / screen.height) * 100}%`,
                    }}
                />
            ))}
        </figure>
    );
}
