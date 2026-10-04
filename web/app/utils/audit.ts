// Small helpers the Audit pages share.

/** The tone for a 0–100 score: good from 80, fair from 50. */
export function scoreTone(score: number): 'success' | 'warning' | 'danger' {
    return score >= 80 ? 'success' : score >= 50 ? 'warning' : 'danger';
}

/** Place a box in screen pixels as percentages of the screen, so it stays on its element at any width. */
export function boxStyle(box: { x: number; y: number; width: number; height: number }, screen: { width: number; height: number }) {
    return {
        left: `${(box.x / screen.width) * 100}%`,
        top: `${(box.y / screen.height) * 100}%`,
        width: `${(box.width / screen.width) * 100}%`,
        height: `${(box.height / screen.height) * 100}%`,
    };
}
