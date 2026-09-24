import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { GhostLink, PageHeader, PrimaryButton } from '@/components/gis';

export default function Share({ project }) {
    const form = useForm({ name: project.name, description: project.description || '', is_public: Boolean(project.is_public) });
    const [copied, setCopied] = useState('');
    const shareUrl = project.share_token ? `${window.location.origin}/projects/shared/${project.share_token}` : '';

    function toggle(event) {
        const isPublic = event.target.checked;
        form.transform((data) => ({ ...data, is_public: isPublic }));
        form.put(`/projects/${project.id}`);
    }

    return (
        <AppLayout title={`Share ${project.name}`}>
            <PageHeader title={`Share ${project.name}`} action={<GhostLink href={`/projects/${project.id}`}>Back</GhostLink>} />
            <label className="flex items-center gap-2">
                <input type="checkbox" checked={Boolean(project.is_public)} onChange={toggle} />
                Make this project public
            </label>
            {project.is_public && shareUrl ? (
                <div className="mt-4 flex max-w-xl gap-2">
                    <input readOnly value={shareUrl} className="flex-1 rounded border border-border bg-field px-3 font-mono" />
                    <PrimaryButton
                        type="button"
                        onClick={async () => {
                            await navigator.clipboard.writeText(shareUrl);
                            setCopied('Copied.');
                        }}
                    >
                        Copy
                    </PrimaryButton>
                </div>
            ) : (
                <p className="mt-3 text-muted-foreground">This project is private. Enable public access to generate a share link.</p>
            )}
            {copied ? <p className="mt-2 text-ok">{copied}</p> : null}
        </AppLayout>
    );
}
