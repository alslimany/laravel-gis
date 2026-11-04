import { Link, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { GhostLink, Heading, PageHeader, Pill } from '@/components/gis';

export default function Show({ project }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props;

    return (
        <AppLayout title={project.name}>
            <PageHeader
                title={project.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        {abilities?.edit ? <GhostLink href={`/projects/${project.id}/edit`}>{t('actions.edit')}</GhostLink> : null}
                        {abilities?.edit ? <GhostLink href={`/projects/${project.id}/share`}>{t('actions.share')}</GhostLink> : null}
                        {abilities?.edit ? <GhostLink href={`/projects/${project.id}/invite`}>{__('projects.invite')}</GhostLink> : null}
                        <GhostLink href="/projects">{t('actions.back')}</GhostLink>
                    </div>
                }
            />
            <div className="mb-3">
                <Pill live={project.is_public}>{project.is_public ? t('common.public') : t('common.private')}</Pill>
            </div>
            <p className="max-w-2xl text-muted-foreground">{project.description || __('projects.no_description')}</p>
            <dl className="mt-4 grid max-w-2xl gap-3 sm:grid-cols-2">
                <div>
                    <dt className="text-muted-foreground">{__('roles.owner')}</dt>
                    <dd>{project.user?.name || t('common.em_dash')}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">{t('projects.created')}</dt>
                    <dd>{when(project.created_at)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">{t('layers.title')}</dt>
                    <dd dir="ltr" className="font-mono">{project.layers?.length || 0}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">{t('projects.collaborators')}</dt>
                    <dd dir="ltr" className="font-mono">{project.collaborators?.length || 0}</dd>
                </div>
            </dl>
            <Heading as="h2">{t('layers.title')}</Heading>
            <ul className="mt-2 max-w-xl divide-y divide-line border border-border">
                {(project.layers || []).length === 0 ? <li className="px-3 py-3 text-muted-foreground">{__('projects.no_layers')}</li> : null}
                {(project.layers || []).map((layer) => (
                    <li key={layer.id} >
                        <Link href={`/layers/${layer.id}`} className="font-medium text-link hover:underline">
                            {layer.name}
                        </Link>
                    </li>
                ))}
            </ul>
        </AppLayout>
    );
}
