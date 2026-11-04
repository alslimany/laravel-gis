import AppLayout from '@/layouts/app-layout';
import { useTranslation, __ } from '@/lib/i18n';
import { usePage } from '@inertiajs/react';
import { Empty, GhostLink, Heading, PageHeader, Panel, Pill } from '@/components/gis';

type AppItem = {
    type: 'map' | 'form' | 'dashboard';
    name: string;
    href: string;
    detail?: string | null;
};

type Workspace = {
    slug: string;
    name: string;
    description?: string | null;
    contents?: string | null;
    items: AppItem[];
    workflow?: WorkflowStep[];
};

type WorkflowStep = {
    title: string;
    description?: string | null;
    kind: 'form' | 'dashboard' | 'map' | 'text';
    target?: string | null;
    href?: string | null;
};

function typeLabel(type: AppItem['type'], t: (key: string) => string): string {
    if (type === 'map') {
        return t('maps.title');
    }
    if (type === 'form') {
        return t('forms_page.title');
    }

    return t('dashboard.title');
}

function stepKindLabel(kind: WorkflowStep['kind'], t: (key: string) => string): string {
    if (kind === 'map') {
        return t('maps.title');
    }
    if (kind === 'form') {
        return t('forms_page.title');
    }
    if (kind === 'dashboard') {
        return t('dashboard.title');
    }

    return t('apps.step');
}

function Runbook({ steps }: { steps: WorkflowStep[] }) {
    const { t } = useTranslation();

    if (!steps.length) {
        return null;
    }
    return (
        <ol className="mt-3 space-y-2 border-t border-border pt-3">
            {steps.map((step, index) => (
                <li key={`${index}-${step.title}`} className="flex gap-2 text-sm">
                    <span dir="ltr" className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-border text-[11px] font-medium tabular-nums text-muted-foreground">
                        {index + 1}
                    </span>
                    <span className="min-w-0">
                        {step.href ? (
                            <a href={step.href} className="font-medium text-link hover:underline">
                                {step.title}
                            </a>
                        ) : (
                            <span className="font-medium">{step.title}</span>
                        )}
                        {step.kind !== 'text' ? (
                            <span className="ms-1.5 inline-block rounded border border-border px-1 text-[10px] uppercase tracking-wide text-muted-foreground">
                                {stepKindLabel(step.kind, t)}
                            </span>
                        ) : null}
                        {step.description ? (
                            <span className="block text-xs text-muted-foreground">{step.description}</span>
                        ) : null}
                        {step.kind !== 'text' && !step.href ? (
                            <span className="mt-0.5 block text-xs text-amber-600">
                                {__('apps.step_missing', { target: step.target || __('apps.target') })}
                            </span>
                        ) : null}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function ItemRow({ item }: { item: AppItem }) {
    const { t } = useTranslation();

    return (
        <li className="flex items-center justify-between gap-3 border-b border-border/70 px-3 py-2 last:border-b-0">
            <span className="min-w-0">
                <a href={item.href} className="block truncate font-medium text-link hover:underline">
                    {item.name}
                </a>
                {item.detail ? <span className="block text-xs text-muted-foreground">{item.detail}</span> : null}
            </span>
            <Pill>{typeLabel(item.type, t)}</Pill>
        </li>
    );
}

function ItemList({ items, empty }: { items: AppItem[]; empty: string }) {
    if (!items.length) {
        return <Empty>{empty}</Empty>;
    }
    return (
        <ul className="rounded border border-border">
            {items.map((item) => (
                <ItemRow key={`${item.type}-${item.href}`} item={item} />
            ))}
        </ul>
    );
}

export default function Index({
    workspaces = [],
    shared = [],
    recent = [],
}: {
    workspaces?: Workspace[];
    shared?: AppItem[];
    recent?: AppItem[];
}) {
    const { t } = useTranslation();
    const { abilities, routes } = usePage().props as {
        abilities?: { admin?: boolean };
        routes?: { solutionPacks?: string | null };
    };

    return (
        <AppLayout title={t('apps.title')}>
            <PageHeader
                title={t('apps.title')}
                action={
                    <div className="flex flex-wrap gap-2">
                        <GhostLink href="/dashboard">{t('projects.back_dashboard')}</GhostLink>
                        {abilities?.admin && routes?.solutionPacks ? (
                            <GhostLink href={routes.solutionPacks}>{t('solution_packs.title')}</GhostLink>
                        ) : null}
                    </div>
                }
            />
            <p className="max-w-2xl text-sm text-muted-foreground">
                {__('apps.intro')}
            </p>

            <section className="space-y-4">
                <Heading as="h2">{__('apps.installed')}</Heading>
                {workspaces.length === 0 ? (
                    <Empty>
                        {__('apps.none')}{' '}
                        {abilities?.admin && routes?.solutionPacks ? (
                            <a href={routes.solutionPacks} className="font-medium text-primary hover:underline">
                                {__('apps.install_pack')}
                            </a>
                        ) : (
                            <span>{__('apps.ask_admin')}</span>
                        )}
                    </Empty>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {workspaces.map((workspace) => (
                            <Panel key={workspace.slug}>
                                <div className="mb-2 flex items-start justify-between gap-2 border-b border-border pb-2">
                                    <div>
                                        <Heading as="h3">{workspace.name}</Heading>
                                        {workspace.contents ? (
                                            <p className="mt-0.5 text-xs text-muted-foreground">{workspace.contents}</p>
                                        ) : null}
                                    </div>
                                    <Pill live>{workspace.items.length} apps</Pill>
                                </div>
                                {workspace.description ? (
                                    <p className="mb-2 text-sm text-muted-foreground">{workspace.description}</p>
                                ) : null}
                                <ItemList items={workspace.items} empty={t('apps.workspace_empty')} />
                                <Runbook steps={workspace.workflow || []} />
                            </Panel>
                        ))}
                    </div>
                )}
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
                <section>
                    <Heading as="h2" className="mb-2">
                        {__('apps.shared')}
                    </Heading>
                    <ItemList items={shared} empty={t('apps.shared_empty')} />
                </section>
                <section>
                    <Heading as="h2" className="mb-2">
                        {__('apps.recent')}
                    </Heading>
                    <ItemList items={recent} empty={t('apps.recent_empty')} />
                </section>
            </div>
        </AppLayout>
    );
}
