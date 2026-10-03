import Link from 'next/link';
import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { t } from '@/lib/i18n';
import type { Translator } from '@/lib/i18n';

type Crumb = { label: string; href?: string };

/** Signal's page header: breadcrumbs, the title and description, and the page's main actions. */
export function PageHeader({ i18n, title, description, eyebrow, icon, breadcrumbs = [], actions, metadata }: {
    i18n: Translator;
    title: string;
    description?: string | null;
    eyebrow?: string;
    icon?: string;
    breadcrumbs?: Crumb[];
    actions?: ReactNode;
    metadata?: ReactNode;
}) {
    return (
        <header className="ui-page-header mb-7 flex scroll-mt-24 flex-col gap-4 border-b border-line pb-6 sm:mb-8 sm:flex-row sm:items-end sm:justify-between" data-ui-page-header>
            <div className="ui-page-header__layout min-w-0 flex-1">
                {breadcrumbs.length > 0 && (
                    <nav aria-label={t(i18n, 'Breadcrumb')} className="mb-4 min-w-0">
                        <ol className="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-muted">
                            {[...breadcrumbs, { label: title }].map((crumb, index) => (
                                <li key={index} className="flex min-w-0 items-center gap-2" aria-current={index === breadcrumbs.length ? 'page' : undefined}>
                                    {index > 0 && <Icon name="chevron-right" className="h-[13px] w-[13px] text-subtle" />}
                                    {crumb.href ? (
                                        <Link className="ui-link" href={crumb.href}>{crumb.label}</Link>
                                    ) : (
                                        <span className={index === breadcrumbs.length ? 'truncate font-bold text-ink' : undefined}>{crumb.label}</span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </nav>
                )}
                <div className="ui-page-header__identity flex min-w-0 max-w-3xl items-start gap-4">
                    {icon && (
                        <span className="ui-page-header__icon mt-2 hidden h-10 w-10 shrink-0 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)] sm:grid" aria-hidden="true">
                            <Icon name={icon} className="h-5 w-5" />
                        </span>
                    )}
                    <div className="min-w-0">
                        {eyebrow && <p className="ui-page-header__eyebrow ui-eyebrow flex items-center gap-2">{eyebrow}</p>}
                        <h1 className="ui-page-header__title mt-1 break-words text-3xl font-extrabold tracking-tight text-ink sm:text-4xl" data-page-title>{title}</h1>
                        {description && <p className="ui-page-header__description mt-3 max-w-2xl text-sm leading-6 text-muted sm:text-base sm:leading-7">{description}</p>}
                        {metadata && <div className="mt-3 flex min-w-0 flex-wrap items-center gap-2">{metadata}</div>}
                    </div>
                </div>
            </div>
            {actions && <div className="ui-page-header__actions flex flex-wrap gap-2">{actions}</div>}
        </header>
    );
}
