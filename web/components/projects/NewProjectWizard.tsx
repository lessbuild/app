'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Field, Input, Textarea } from '@/components/signal/Field';
import { Icon } from '@/components/signal/Icon';
import { Wizard, type WizardStep } from '@/components/signal/Wizard';
import { send, ValidationError } from '@/lib/client';
import { flash } from '@/lib/flash';
import { useT } from '@/lib/i18n-client';
import type { ServiceOption } from '@/lib/projects';
import { local } from '@/lib/url';

/** Create a project in three steps: what it is, which services it needs, and a check before creating it. */
export function NewProjectWizard({ services }: { services: ServiceOption[] }) {
    const { t } = useT();
    const router = useRouter();
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<ValidationError | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [chosen, setChosen] = useState<string[]>([]);

    async function create() {
        setBusy(true);
        setError(null);
        try {
            const result = await send<{ redirect: string; message: string }>('POST', '/projects', { name, description: description || null, services: chosen });
            flash(result.message);
            router.push(local(result.redirect));
        } catch (problem) {
            if (problem instanceof ValidationError) {
                setErrors(problem);
                setStep(problem.first('name') || problem.first('description') ? 0 : 1);
            }
            setError(problem instanceof Error ? problem.message : t('Something went wrong. Try again.'));
            setBusy(false);
        }
    }

    const steps: WizardStep[] = [
        {
            id: 'details',
            title: t('Your project'),
            description: t('One project per app or site. It starts with a Production environment; you can add staging and others later.'),
            validate: () => (name.trim() === '' ? t('Give the project a name.') : null),
            content: (
                <div className="grid gap-4">
                    <Field id="project-name" label={t('Project name')} error={errors?.first('name')}>
                        <Input id="project-name" value={name} onChange={(event) => setName(event.target.value)} maxLength={100} autoComplete="off" required autoFocus invalid={!!errors?.first('name')} />
                    </Field>
                    <Field id="project-description" label={t('Description')} description={t('Optional. What this project is, for your teammates.')} error={errors?.first('description')}>
                        <Textarea id="project-description" rows={3} maxLength={500} value={description} onChange={(event) => setDescription(event.target.value)} />
                    </Field>
                </div>
            ),
        },
        {
            id: 'services',
            title: t('What do you need?'),
            description: t('Use one service or several. Analytics and Monitoring work with any site, wherever it’s hosted, with no server here. You can change this later.'),
            content: (
                <fieldset className="grid gap-2 sm:grid-cols-2">
                    <legend className="sr-only">{t('Services')}</legend>
                    {services.map((service) => {
                        const on = chosen.includes(service.key);
                        return (
                            <label key={service.key} className={`flex cursor-pointer items-start gap-3 rounded-card border p-4 transition ${on ? 'border-primary bg-primary-soft' : 'border-line hover:border-subtle'}`}>
                                <input type="checkbox" className="ui-check mt-1" checked={on} onChange={(event) => setChosen(event.target.checked ? [...chosen, service.key] : chosen.filter((key) => key !== service.key))} />
                                <span className={`product-icon-${service.key === 'monitoring' ? 'monitor' : service.key} grid size-9 shrink-0 place-items-center rounded-card`} aria-hidden="true">
                                    <Icon name={service.icon} className="size-5" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-sm font-extrabold text-ink">{service.name}</span>
                                    <span className="mt-0.5 block text-xs leading-5 text-muted">{service.tagline}</span>
                                </span>
                            </label>
                        );
                    })}
                </fieldset>
            ),
        },
        {
            id: 'review',
            title: t('Check and create'),
            content: (
                <dl className="grid gap-3 rounded-card bg-surface-muted p-4 text-sm sm:grid-cols-[9rem_1fr]">
                    <dt className="font-bold text-muted">{t('Name')}</dt>
                    <dd className="text-ink">{name}</dd>
                    {description && (
                        <>
                            <dt className="font-bold text-muted">{t('Description')}</dt>
                            <dd className="text-ink">{description}</dd>
                        </>
                    )}
                    <dt className="font-bold text-muted">{t('Services')}</dt>
                    <dd className="text-ink">{chosen.length ? services.filter((service) => chosen.includes(service.key)).map((service) => service.name).join(' · ') : t('None yet. You can turn them on from the project.')}</dd>
                </dl>
            ),
        },
    ];

    return <Wizard steps={steps} current={step} onStepChange={setStep} busy={busy} error={error} finishLabel={t('Create project')} onFinish={create} />;
}
