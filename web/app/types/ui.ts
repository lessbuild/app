// Shared types for the Signal components.

/** A badge's or alert's colour, by meaning. */
export type Tone = 'neutral' | 'accent' | 'info' | 'success' | 'warning' | 'danger';

/** An option in a select. */
export type Option = { value: string; label: string; disabled?: boolean };
