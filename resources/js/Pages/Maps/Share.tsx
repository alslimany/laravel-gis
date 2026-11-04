import { useForm } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { GhostLink, PageHeader, PrimaryButton } from '@/components/gis';

export default function Share({ map }) {
    const { t } = useTranslation();
    const form = useForm({
        name: map.name,
        is_public: Boolean(map.is_public),
        return_to_share: true,
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
            <PageHeader title={`Share ${map.name}`} action={<GhostLink href={`/maps/${map.id}`}>{t('actions.back')}</GhostLink>} />
            <label className="flex items-center gap-2">
                <input type="checkbox" checked={form.data.is_public} onChange={toggle} />
                {__('maps.make_public')}
            </label>
            <p className="mt-2 text-muted-foreground">{__('maps.public_note')}</p>
            {map.is_public && shareUrl ? (
                <div className="mt-6 max-w-xl space-y-4">
                    <div>
                        <p >{__('maps.share_link')}</p>
                        <div className="mt-2 flex gap-2">
                            <input readOnly value={shareUrl} className="flex-1 rounded border border-border bg-field px-3 font-mono" />
                            <PrimaryButton type="button" onClick={() => copy(shareUrl, 'link')}>
                                {__('common.copy')}
                            </PrimaryButton>
                        </div>
                    </div>
                    <div>
                        <p >{t('maps.embed')}</p>
                        <textarea
                            readOnly
                            className="mt-2 min-h-20 w-full rounded border border-border bg-field p-3 font-mono"
                            value={`<iframe src="${shareUrl}" width="100%" height="600" frameborder="0"></iframe>`}
                        />
                        <button type="button" className="mt-2 font-medium text-link" onClick={() => copy(`<iframe src="${shareUrl}" width="100%" height="600" frameborder="0"></iframe>`, 'embed')}>
                            {__('maps.copy_embed')}
                        </button>
                    </div>
                    {copied ? <p className="text-ok">{__('common.copied')} {copied}.</p> : null}
                </div>
            ) : (
                <p className="mt-4 text-muted-foreground">{__('maps.private_note')}</p>
            )}
        </AppLayout>
    );
}
