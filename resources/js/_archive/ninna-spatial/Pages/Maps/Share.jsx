import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { GhostLink, PageHeader, PrimaryButton } from '../../Components/ui';

export default function Share({ map }) {
    const form = useForm({
        name: map.name,
        is_public: Boolean(map.is_public),
    });
    const [copied, setCopied] = useState('');
    const shareUrl = map.share_token ? `${window.location.origin}/maps/shared/${map.share_token}` : '';

    function toggle(event) {
        const isPublic = event.target.checked;
        form.setData('is_public', isPublic);
        form.transform((data) => ({ ...data, is_public: isPublic }));
        form.put(`/maps/${map.id}`);
    }

    async function copy(value, label) {
        await navigator.clipboard.writeText(value);
        setCopied(label);
    }

    return (
        <AppLayout title={`Share ${map.name}`}>
            <PageHeader title={`Share ${map.name}`} action={<GhostLink href={`/maps/${map.id}`}>Back</GhostLink>} />
            <label className="flex items-center gap-2">
                <input type="checkbox" checked={form.data.is_public} onChange={toggle} />
                Make this map public
            </label>
            <p className="mt-2 text-muted">Public maps can be viewed by anyone with the share link.</p>
            {map.is_public && shareUrl ? (
                <div className="mt-6 max-w-xl space-y-4">
                    <div>
                        <p >Share link</p>
                        <div className="mt-2 flex gap-2">
                            <input readOnly value={shareUrl} className="flex-1 rounded border border-line bg-field px-3 font-mono" />
                            <PrimaryButton type="button" onClick={() => copy(shareUrl, 'link')}>
                                Copy
                            </PrimaryButton>
                        </div>
                    </div>
                    <div>
                        <p >Embed</p>
                        <textarea
                            readOnly
                            className="mt-2 min-h-20 w-full rounded border border-line bg-field p-3 font-mono"
                            value={`<iframe src="${shareUrl}" width="100%" height="600" frameborder="0"></iframe>`}
                        />
                        <button type="button" className="mt-2 font-medium text-link" onClick={() => copy(`<iframe src="${shareUrl}" width="100%" height="600" frameborder="0"></iframe>`, 'embed')}>
                            Copy embed
                        </button>
                    </div>
                    {copied ? <p className="text-ok">Copied {copied}.</p> : null}
                </div>
            ) : (
                <p className="mt-4 text-muted">This map is private. Turn on public access to generate a share link.</p>
            )}
        </AppLayout>
    );
}
