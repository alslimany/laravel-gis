import { Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { GhostLink, Heading, PageHeader, Pill } from '@/components/gis';

export default function Show({ project }) {
    const { abilities } = usePage().props;

    return (
        <AppLayout title={project.name}>
            <PageHeader
                title={project.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        {abilities?.edit ? <GhostLink href={`/projects/${project.id}/edit`}>Edit</GhostLink> : null}
                        {abilities?.edit ? <GhostLink href={`/projects/${project.id}/share`}>Share</GhostLink> : null}
                        {abilities?.edit ? <GhostLink href={`/projects/${project.id}/invite`}>Invite</GhostLink> : null}
                        <GhostLink href="/projects">Back</GhostLink>
                    </div>
                }
            />
            <div className="mb-3">
                <Pill live={project.is_public}>{project.is_public ? 'Public' : 'Private'}</Pill>
            </div>
            <p className="max-w-2xl text-muted-foreground">{project.description || 'No description provided.'}</p>
            <dl className="mt-4 grid max-w-2xl gap-3 sm:grid-cols-2">
                <div>
                    <dt className="text-muted-foreground">Owner</dt>
                    <dd>{project.user?.name || '—'}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Created</dt>
                    <dd>{when(project.created_at)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Layers</dt>
                    <dd className="font-mono">{project.layers?.length || 0}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Collaborators</dt>
                    <dd className="font-mono">{project.collaborators?.length || 0}</dd>
                </div>
            </dl>
            <Heading as="h2">Layers</Heading>
            <ul className="mt-2 max-w-xl divide-y divide-line border border-border">
                {(project.layers || []).length === 0 ? <li className="px-3 py-3 text-muted-foreground">No layers in this project.</li> : null}
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
