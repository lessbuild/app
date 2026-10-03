import type { ReactNode } from 'react';
import { Alert } from '@/components/signal/Alert';
import { Icon } from '@/components/signal/Icon';
import { local } from '@/lib/api';
import { t, tc, type Translator } from '@/lib/i18n';
import { I18nProvider } from '@/lib/i18n-client';
import type { Shell } from '@/lib/types';
import { NavLinks } from './NavLinks';
import { SignOutButton } from './SignOutButton';
import { ThemeToggle } from './ThemeToggle';

// Paths the Next.js app serves; links to them navigate client-side. Everything else is still a Laravel page.
const moved = ['/audit'];

/** The signed-in frame, as the Blade layout draws it: two topbar rows, then the page. */
export function AppShell({ shell, i18n, service, children }: { shell: Shell; i18n: Translator; service?: string; children: ReactNode }) {
    const link = (item: { label: string; url: string }) => ({ label: item.label, href: local(item.url) });

    return (
        <I18nProvider value={i18n}>
            <a href="#main-content" className="ui-skip-link">{t(i18n, 'Skip to main content')}</a>
            <header className="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
                <div className="ui-layout-gutter mx-auto max-w-content">
                    <div className="flex min-h-16 items-center gap-3">
                        <details className="relative xl:hidden">
                            <summary className="ui-icon-btn list-none" aria-label={t(i18n, 'Open navigation')}><Icon name="menu" className="h-5 w-5" /></summary>
                            <div className="absolute left-0 top-12 z-50 grid w-72 gap-1 rounded-panel border border-line bg-surface p-3 shadow-panel">
                                <NavLinks items={shell.primaryNav.map(link)} label={t(i18n, 'Platform')} className="flex-col items-stretch" moved={moved} service={service} />
                            </div>
                        </details>
                        <a href={local(shell.links.dashboard)} className="flex min-w-0 shrink-0 items-center gap-2.5 text-sm font-extrabold tracking-tight text-ink" aria-label={t(i18n, ':app home', { app: 'BuildPusher' })}>
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface shadow-soft" aria-hidden="true">↗</span>
                            <span className="hidden truncate sm:inline">BuildPusher</span>
                        </a>
                        <NavLinks items={shell.primaryNav.map(link)} label={t(i18n, 'Platform')} className="hidden flex-1 pl-2 xl:flex" moved={moved} service={service} />
                        <div className="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
                            <a href={local(shell.links.changelog)} className="ui-icon-btn relative" aria-label={shell.unseenChanges > 0 ? t(i18n, 'What’s new (:count new)', { count: shell.unseenChanges }) : t(i18n, 'What’s new')}>
                                <Icon name="sparkles" className="h-[18px] w-[18px]" />
                                {shell.unseenChanges > 0 && <span className="absolute right-1 top-1 size-2 rounded-full bg-primary" aria-hidden="true" />}
                            </a>
                            <a href={local(shell.links.notifications)} className="ui-icon-btn relative" aria-label={shell.unreadNotifications > 0 ? tc(i18n, 'Notifications, :count unread|Notifications, :count unread', shell.unreadNotifications) : t(i18n, 'Notifications')}>
                                <Icon name="bell" className="h-[18px] w-[18px]" />
                                {shell.unreadNotifications > 0 && (
                                    <span className="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-extrabold leading-4 text-white" aria-hidden="true">
                                        {shell.unreadNotifications > 9 ? '9+' : shell.unreadNotifications}
                                    </span>
                                )}
                            </a>
                            <ThemeToggle />
                            <details className="ui-topbar-menu group relative">
                                <summary className="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-control px-1.5 text-sm font-bold text-ink hover:bg-surface-muted" aria-label={t(i18n, 'Account menu for :name', { name: shell.user.name })}>
                                    <span className="ui-avatar ui-avatar-sm text-xs" aria-hidden="true">{shell.user.name.slice(0, 1).toUpperCase()}</span>
                                </summary>
                                <div className="absolute right-0 top-full z-40 mt-2 grid min-w-60 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                                    <div className="border-b border-line px-3 pb-3 pt-2">
                                        <p className="truncate text-sm font-extrabold text-ink">{shell.user.name}</p>
                                        <p className="truncate text-xs text-muted">{shell.user.email}</p>
                                    </div>
                                    {shell.accountLinks.length > 0 && (
                                        <>
                                            <p className="px-3 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{shell.account?.name}</p>
                                            {shell.accountLinks.map((item) => <a key={item.url} href={local(item.url)} className="topbar-nav-link w-full">{item.label}</a>)}
                                        </>
                                    )}
                                    <div className="mt-1 grid gap-1 border-t border-line pt-1">
                                        <a href={local(shell.links.settings)} className="topbar-nav-link w-full">{t(i18n, 'Your settings')}</a>
                                        <a href={local(shell.links.help)} className="topbar-nav-link w-full">{t(i18n, 'Help centre')}</a>
                                        <SignOutButton action={local(shell.links.logout)} />
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>
                    {shell.account !== null && (
                        <div className="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-line py-2">
                            <div className="flex min-w-0 flex-wrap items-center gap-2 text-sm font-bold text-ink">
                                {shell.project ? (
                                    <a href={`/projects/${shell.project.id}`} className="flex min-w-0 items-center gap-2 rounded-control px-2 py-1.5 hover:bg-surface-muted">
                                        <Icon name="layers" className="h-4 w-4 text-muted" />
                                        <span className="truncate">{shell.project.name}</span>
                                    </a>
                                ) : (
                                    <span className="px-2 text-muted">{shell.account.name}</span>
                                )}
                            </div>
                            {shell.sectionNav.length > 0 && <NavLinks items={shell.sectionNav.map(link)} label={shell.sectionLabel} className="max-w-full justify-end" moved={moved} />}
                        </div>
                    )}
                </div>
            </header>
            <main id="main-content" tabIndex={-1} className="ui-layout-gutter mx-auto w-full max-w-content space-y-6 pb-20 pt-7 sm:pt-9">
                {shell.limitWarning && (
                    <Alert tone={shell.limitWarning.tone} role="status">
                        {shell.limitWarning.message} <a href={local(shell.limitWarning.url)} className="font-semibold underline">{shell.limitWarning.linkLabel}</a>
                    </Alert>
                )}
                {children}
            </main>
        </I18nProvider>
    );
}

