import PublicLayout from '@/layouts/public-layout';
import { useTranslation } from '@/lib/i18n';
import { Heading, Pill } from '@/components/gis';

export default function Shared({ project }) {
    const { t } = useTranslation();
    return (
        <PublicLayout title={project.name}>
            <Heading as="h1">{project.name}</Heading>
            <div className="mt-2">
                <Pill live>{t('common.public')}</Pill>
            </div>
            <p className="mt-3 max-w-2xl text-muted-foreground">{project.description || 'No description.'}</p>
            <p className="mt-2 text-muted-foreground">Owner {project.user?.name || t('common.em_dash')}</p>
            <ul className="mt-6 max-w-xl divide-y divide-line border border-border">
                {(project.layers || []).map((layer) => (
                    <li key={layer.id} >
                        {layer.name}
                    </li>
                ))}
            </ul>
        </PublicLayout>
    );
}
