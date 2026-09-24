import { usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { GhostLink, PageHeader, Pill } from '@/components/gis';

export default function Show({ form }) {
    const { abilities } = usePage().props;

    return (
        <AppLayout title={form.name}>
            <PageHeader
                title={form.name}
                action={
                    <div className="flex gap-2">
                        {abilities?.edit ? <GhostLink href={`/forms/${form.id}/edit`}>Edit</GhostLink> : null}
                        {form.is_public && form.share_token ? <GhostLink href={`/f/${form.share_token}`}>Public link</GhostLink> : null}
                        <GhostLink href="/forms">Back</GhostLink>
                    </div>
                }
            />
            <Pill live={form.is_public}>{form.is_public ? 'Public' : 'Private'}</Pill>
            <p className="mt-3 text-muted-foreground">{form.description || '—'}</p>
            <p className="mt-2">Layer {form.layer?.name || '—'}</p>
            <ul className="mt-4 max-w-xl divide-y divide-line border border-border">
                {(form.schema || []).map((field, index) => (
                    <li key={index} className="flex justify-between">
                        <span>{field.label || field.name}</span>
                        <span className="font-mono text-muted-foreground">{field.type}</span>
                    </li>
                ))}
            </ul>
        </AppLayout>
    );
}
