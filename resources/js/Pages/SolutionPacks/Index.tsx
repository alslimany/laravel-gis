import { router } from '@inertiajs/react';
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
};

export default function SolutionPacks({ packs = [], canManage = false }: { packs?: PackRow[]; canManage?: boolean }) {
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
        if (window.confirm(`Delete pack "${pack.name}"? Installed layers are not removed.`)) {
            router.delete(`/solution-packs/${pack.id}`, { preserveScroll: true });
        }
    }

    return (
        <AppLayout title="Solution packs">
            <PageHeader
                title="Solution packs"
                action={
                    <div className="flex items-center gap-2">
                        <GhostLink href="/apps">Open app gallery</GhostLink>
                        <GhostLink href="/organization/settings">Back to settings</GhostLink>
                        {canManage ? <PrimaryLink href="/solution-packs/create">New pack</PrimaryLink> : null}
                    </div>
                }
            />
            <p className="max-w-2xl text-sm text-base-600">
                Packs are blueprints of layers, relationships, and workspace items you can install into this organization.
                Built-in packs are read-only; duplicate one to customize it, or start from scratch.
            </p>

            <Panel>
                {packs.length === 0 ? (
                    <Empty>No solution packs yet.</Empty>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <th className={thClass}>Pack</th>
                                <th className={thClass}>Contents</th>
                                <th className={thClass}>Status</th>
                                <th className={`${thClass} text-right`}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {packs.map((pack) => (
                                <tr key={`${pack.custom ? 'c' : 'b'}-${pack.slug}`} className="border-t border-border">
                                    <td className={tdClass}>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{pack.name}</span>
                                            <Pill>{pack.custom ? 'Custom' : 'Built-in'}</Pill>
                                        </div>
                                        <p className={tdMuted}>{pack.description}</p>
                                        {pack.workflow && pack.workflow.length > 0 ? (
                                            <details className="mt-1">
                                                <summary className="cursor-pointer text-xs text-link select-none">
                                                    Workflow ({pack.workflow.length} steps)
                                                </summary>
                                                <ol className="mt-1 list-decimal space-y-1 pl-4 text-xs text-muted-foreground">
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
                                                Installed{pack.unpublished > 0 ? ` · ${pack.unpublished} unpublished` : ''}
                                            </span>
                                        ) : (
                                            <span className={`text-sm ${tdMuted}`}>Not installed</span>
                                        )}
                                    </td>
                                    <td className={`${tdClass} text-right`}>
                                        <RowActions>
                                            {pack.custom && pack.id && canManage ? (
                                                <ActionLink href={`/solution-packs/${pack.id}/edit`}>Edit</ActionLink>
                                            ) : null}
                                            {!pack.installed && canManage ? (
                                                <PrimaryButton type="button" size="sm" onClick={() => install(pack.slug)}>
                                                    Install
                                                </PrimaryButton>
                                            ) : null}
                                            {pack.installed && pack.unpublished > 0 && canManage ? (
                                                <PrimaryButton type="button" size="sm" variant="secondary" onClick={() => publish(pack.slug)}>
                                                    Publish {pack.unpublished} {pack.unpublished === 1 ? 'layer' : 'layers'}
                                                </PrimaryButton>
                                            ) : null}
                                            {!pack.custom && canManage ? (
                                                <Button type="button" size="sm" variant="outline" onClick={() => duplicate(pack.slug)}>
                                                    Duplicate
                                                </Button>
                                            ) : null}
                                            {pack.custom && canManage ? (
                                                <DangerButton onClick={() => remove(pack)}>Delete</DangerButton>
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
