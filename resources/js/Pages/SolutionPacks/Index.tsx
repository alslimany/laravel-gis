import { router } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import {
    ActionLink,
    DangerButton,
    DataTable,
    Empty,
    GhostLink,
    PageHeader,
    Panel,
    Pill,
    PrimaryButton,
    PrimaryLink,
    RowActions,
    tdClass,
    tdMuted,
    thClass,
} from '@/components/gis';
import { Button } from '@/components/ui/button';

type PackRow = {
    id: number | null;
    slug: string;
    name: string;
    description?: string | null;
    contents?: string | null;
    installed: boolean;
    custom: boolean;
    unpublished: number;
    workflow?: Array<{
        title: string;
        description?: string | null;
        kind: string;
        target?: string | null;
        href?: string | null;
    }>;
    workflow_label?: string;
};

export default function SolutionPacks({ packs = [], canManage = false }: { packs?: PackRow[]; canManage?: boolean }) {
    const { t } = useTranslation();
    function install(slug: string) {
        router.post(`/organization/solution-packs/${slug}`, {}, { preserveScroll: true });
    }

    function publish(slug: string) {
        router.post(`/organization/solution-packs/${slug}/publish`, {}, { preserveScroll: true });
    }

    function duplicate(slug: string) {
        router.post(`/solution-packs/${slug}/duplicate`, {}, { preserveScroll: true });
    }

    function remove(pack: PackRow) {
        if (!pack.id) {
            return;
        }
        if (window.confirm(__('packs.confirm_delete', { name: pack.name }))) {
            router.delete(`/solution-packs/${pack.id}`, { preserveScroll: true });
        }
    }

    return (
        <AppLayout title={t('solution_packs.title')}>
            <PageHeader
                title={t('solution_packs.title')}
                action={
                    <div className="flex items-center gap-2">
                        <GhostLink href="/apps">{__('packs.gallery')}</GhostLink>
                        <GhostLink href="/organization/settings">{t('settings.back_settings')}</GhostLink>
                        {canManage ? <PrimaryLink href="/solution-packs/create">{__('packs.new')}</PrimaryLink> : null}
                    </div>
                }
            />
            <p className="max-w-2xl text-sm text-base-600">
                {__('packs.intro')}
            </p>

            <Panel>
                {packs.length === 0 ? (
                    <Empty>{__('packs.empty')}</Empty>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <th className={thClass}>{__('packs.pack')}</th>
                                <th className={thClass}>{t('common.contents')}</th>
                                <th className={thClass}>{t('forms.status')}</th>
                                <th className={`${thClass} text-end`}>{t('forms.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {packs.map((pack) => (
                                <tr key={`${pack.custom ? 'c' : 'b'}-${pack.slug}`} className="border-t border-border">
                                    <td className={tdClass}>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{pack.name}</span>
                                            <Pill>{pack.custom ? __('packs.custom') : __('packs.builtin')}</Pill>
                                        </div>
                                        <p className={tdMuted}>{pack.description}</p>
                                        {pack.workflow && pack.workflow.length > 0 ? (
                                            <details className="mt-1">
                                                <summary className="cursor-pointer text-xs text-link select-none">
                                                    {pack.workflow_label}
                                                </summary>
                                                <ol className="mt-1 list-decimal space-y-1 ps-4 text-xs text-muted-foreground">
                                                    {pack.workflow.map((step, index) => (
                                                        <li key={index}>
                                                            {step.href ? (
                                                                <a href={step.href} className="text-link hover:underline">
                                                                    {step.title}
                                                                </a>
                                                            ) : (
                                                                <span>{step.title}</span>
                                                            )}
                                                            {step.description ? ` — ${step.description}` : ''}
                                                        </li>
                                                    ))}
                                                </ol>
                                            </details>
                                        ) : null}
                                    </td>
                                    <td className={`${tdClass} text-xs ${tdMuted}`}>{pack.contents}</td>
                                    <td className={tdClass}>
                                        {pack.installed ? (
                                            <span className="text-sm font-medium text-emerald-600">
                                                {__('packs.installed')}{pack.unpublished > 0 ? ` · ${__('packs.unpublished', { count: pack.unpublished })}` : ''}
                                            </span>
                                        ) : (
                                            <span className={`text-sm ${tdMuted}`}>{__('packs.not_installed')}</span>
                                        )}
                                    </td>
                                    <td className={`${tdClass} text-end`}>
                                        <RowActions>
                                            {pack.custom && pack.id && canManage ? (
                                                <ActionLink href={`/solution-packs/${pack.id}/edit`}>{t('actions.edit')}</ActionLink>
                                            ) : null}
                                            {!pack.installed && canManage ? (
                                                <PrimaryButton type="button" size="sm" onClick={() => install(pack.slug)}>
                                                    {__('packs.install')}
                                                </PrimaryButton>
                                            ) : null}
                                            {pack.installed && pack.unpublished > 0 && canManage ? (
                                                <PrimaryButton type="button" size="sm" variant="secondary" onClick={() => publish(pack.slug)}>
                                                    {__('packs.publish')} {pack.unpublished} {pack.unpublished === 1 ? 'layer' : 'layers'}
                                                </PrimaryButton>
                                            ) : null}
                                            {!pack.custom && canManage ? (
                                                <Button type="button" size="sm" variant="outline" onClick={() => duplicate(pack.slug)}>
                                                    {__('packs.duplicate')}
                                                </Button>
                                            ) : null}
                                            {pack.custom && canManage ? (
                                                <DangerButton onClick={() => remove(pack)}>{t('actions.delete')}</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
            </Panel>
        </AppLayout>
    );
}
