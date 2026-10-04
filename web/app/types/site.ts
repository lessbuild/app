// The JSON the public site's endpoints return.

/** What a public page tells search engines and link previews. */
export type PageMeta = { title: string; description: string; canonical: string; image: string; structuredData: Record<string, unknown> };

/** The public site's frame: the services for its navigation, its summary and contact address. */
export type SiteFrame = { services: Array<{ key: string; name: string; icon: string; accent: string; eyebrow: string; summary: string }>; summary: string; contactEmail: string; signedIn: boolean };

/** A service's marketing copy (config/marketing.php), translated. */
export type ServiceCopy = {
    accent: string;
    icon: string;
    eyebrow: string;
    headline: string;
    summary: string;
    card_summary: string;
    card_features: string[];
    suite: { title: string; description: string; features: string[] };
    capabilities: string[];
    preview: {
        title: string; context: string; description: string; status: string; status_tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
        metrics: Array<[string, string]>; activity_label: string; activity: Array<[string, string, string]>;
    };
    highlights_heading: string;
    highlights: Array<{ icon: string; title: string; text: string }>;
    groups: Array<{ label: string; title: string; description: string; features: Array<[string, string]> }>;
    workflows_heading: string;
    workflows_intro: string;
    workflows: Array<[string, string]>;
    guardrails_title: string;
    guardrails_description: string;
    guardrails: string[];
    together: Array<[string, string]>;
    questions: Array<[string, string]>;
};
