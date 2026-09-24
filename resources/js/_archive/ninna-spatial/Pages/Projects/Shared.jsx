import PublicLayout from '../../Layouts/PublicLayout';
import { Heading, Pill } from '../../Components/ui';

export default function Shared({ project }) {
    return (
        <PublicLayout title={project.name}>
            <Heading as="h1">{project.name}</Heading>
            <div className="mt-2">
                <Pill live>Public</Pill>
            </div>
            <p className="mt-3 max-w-2xl text-muted">{project.description || 'No description.'}</p>
            <p className="mt-2 text-muted">Owner {project.user?.name || '—'}</p>
            <ul className="mt-6 max-w-xl divide-y divide-line border border-line">
                {(project.layers || []).map((layer) => (
                    <li key={layer.id} >
                        {layer.name}
                    </li>
                ))}
            </ul>
        </PublicLayout>
    );
}
