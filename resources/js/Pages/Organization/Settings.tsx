import { useEffect, useState } from 'react';
import { useTranslation, __ } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Field, PageHeader, PrimaryButton, TextArea, TextInput } from '@/components/gis';
import { clearTileCache, tileCacheCount } from '@/map-workspace/cache/tileCache';

export default function Settings({ organization, solutionPacks = [], canInstallPacks = false }) {
    const { t } = useTranslation();
    const [tileCount, setTileCount] = useState(null);
    const [clearing, setClearing] = useState(false);
    const [installing, setInstalling] = useState(null);

    useEffect(() => {
        tileCacheCount().then(setTileCount).catch(() => setTileCount(0));
    }, []);

    async function emptyMapCache() {
        setClearing(true);
        try {
            await clearTileCache();
            setTileCount(0);
        } finally {
            setClearing(false);
        }
    }

    const form = useForm({
        name: organization.name || '',
        description: organization.description || '',
        primary_color: organization.primary_color || '#06b6d4',
        logo: null,
    });

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({ ...data, _method: 'put' }));
        form.post('/organization', { forceFormData: true });
    }

    return (
        <AppLayout title={t('organization.title')}>
            <PageHeader title={t('organization.title')} />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label={t('forms.organization_name')} error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label={t('forms.description')}>
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <Field label={t('forms.primary_color')} error={form.errors.primary_color}>
                    <TextInput value={form.data.primary_color} onChange={(event) => form.setData('primary_color', event.target.value)} />
                </Field>
                <Field label={t('forms.logo')} error={form.errors.logo}>
                    <input type="file" accept="image/*" className="block" onChange={(event) => form.setData('logo', event.target.files?.[0] || null)} />
                </Field>
                {organization.logo_path ? <img src={`/storage/${organization.logo_path}`} alt="" className="h-16" /> : null}
                <PrimaryButton type="submit" disabled={form.processing}>
                    {__('org.save')}
                </PrimaryButton>
            </form>
            <section className="mt-8 max-w-xl space-y-3 border-t border-border pt-6">
                <h2 className="text-lg font-semibold">{__('org.tile_cache')}</h2>
                <p className="text-sm text-base-600">
                    {__('org.tile_cache_help')}
                    {tileCount === null ? '' : ` ${__(tileCount === 1 ? 'org.tiles_one' : 'org.tiles_many', { count: tileCount })}`}
                </p>
                <PrimaryButton type="button" onClick={emptyMapCache} disabled={clearing}>
                    {clearing ? __('org.emptying') : __('org.empty_cache')}
                </PrimaryButton>
            </section>
            <section className="mt-8 max-w-xl space-y-3 border-t border-border pt-6">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">{t('solution_packs.title')}</h2>
                    {canInstallPacks ? (
                        <div className="flex items-center gap-4">
                            <a href="/solution-packs" className="text-sm font-medium text-primary hover:underline">
                                {__('org.build_pack')}
                            </a>
                            <a href="/apps" className="text-sm font-medium text-primary hover:underline">
                                {__('org.app_gallery')}
                            </a>
                        </div>
                    ) : null}
                </div>
                <p className="text-sm text-base-600">
                    {__('org.packs_help')}
                </p>
                <div className="space-y-3">
                    {solutionPacks.map((pack) => (
                        <div key={pack.slug} className="flex items-start justify-between gap-4 border border-border rounded-lg p-4">
                            <div>
                                <p className="font-medium">
                                    {pack.name}
                                    <span className="ms-2 align-middle text-xs font-normal text-base-500">
                                        {pack.custom ? __('packs.custom') : __('packs.builtin')}
                                    </span>
                                </p>
                                <p className="text-sm text-base-600">{pack.description}</p>
                                {pack.contents ? <p className="text-xs text-base-500 mt-0.5">{pack.contents}</p> : null}
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                {pack.installed && pack.unpublished > 0 && canInstallPacks ? (
                                    <PrimaryButton
                                        type="button"
                                        variant="secondary"
                                        disabled={installing === pack.slug}
                                        onClick={() => {
                                            setInstalling(pack.slug);
                                            router.post(`/organization/solution-packs/${pack.slug}/publish`, {}, { onFinish: () => setInstalling(null) });
                                        }}
                                    >
                                        {installing === pack.slug ? __('org.publishing') : __('org.publish_count', { count: pack.unpublished })}
                                    </PrimaryButton>
                                ) : null}
                                {pack.installed ? (
                                    <span className="text-sm font-medium text-emerald-600">{__('packs.installed')}</span>
                                ) : canInstallPacks ? (
                                    <PrimaryButton
                                        type="button"
                                        disabled={installing === pack.slug}
                                        onClick={() => {
                                            setInstalling(pack.slug);
                                            router.post(`/organization/solution-packs/${pack.slug}`, {}, { onFinish: () => setInstalling(null) });
                                        }}
                                    >
                                        {installing === pack.slug ? __('org.installing') : __('packs.install')}
                                    </PrimaryButton>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        </AppLayout>
    );
}
