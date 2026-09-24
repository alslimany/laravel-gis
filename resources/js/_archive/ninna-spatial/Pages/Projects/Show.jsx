import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { when } from '../../lib/format';
import { GhostLink, Heading, PageHeader, Pill } from '../../Components/ui';

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
            <p className="max-w-2xl text-muted">{project.description || 'No description provided.'}</p>
            <dl className="mt-4 grid max-w-2xl gap-3 sm:grid-cols-2">
                <div>
                    <dt className="text-muted">Owner</dt>
                    <dd>{project.user?.name || '—'}</dd>
                </div>
                <div>
                    <dt className="text-muted">Created</dt>
                    <dd>{when(project.created_at)}</dd>
                </div>
                <div>
                    <dt className="text-muted">Layers</dt>
                    <dd className="font-mono">{project.layers?.length || 0}</dd>
                </div>
                <div>
                    <dt className="text-muted">Collaborators</dt>
                    <dd className="font-mono">{project.collaborators?.length || 0}</dd>
                </div>
            </dl>
            <Heading as="h2">Layers</Heading>
            <ul className="mt-2 max-w-xl divide-y divide-line border border-line">
                {(project.layers || []).length === 0 ? <li className="px-3 py-3 text-muted">No layers in this project.</li> : null}
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
