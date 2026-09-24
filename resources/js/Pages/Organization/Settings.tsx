import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Field, PageHeader, PrimaryButton, TextArea, TextInput } from '@/components/gis';
import { clearTileCache, tileCacheCount } from '@/map-workspace/cache/tileCache';

export default function Settings({ organization }) {
    const [tileCount, setTileCount] = useState(null);
    const [clearing, setClearing] = useState(false);

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
        <AppLayout title="Organization">
            <PageHeader title="Organization" />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Organization name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Description">
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <Field label="Primary color" error={form.errors.primary_color}>
                    <TextInput value={form.data.primary_color} onChange={(event) => form.setData('primary_color', event.target.value)} />
                </Field>
                <Field label="Logo" error={form.errors.logo}>
                    <input type="file" accept="image/*" className="block" onChange={(event) => form.setData('logo', event.target.files?.[0] || null)} />
                </Field>
                {organization.logo_path ? <img src={`/storage/${organization.logo_path}`} alt="" className="h-16" /> : null}
                <PrimaryButton type="submit" disabled={form.processing}>
                    Save organization
                </PrimaryButton>
            </form>
            <section className="mt-8 max-w-xl space-y-3 border-t border-border pt-6">
                <h2 className="text-lg font-semibold">Map tile cache</h2>
                <p className="text-sm text-base-600">
                    Basemap and imagery tiles already loaded in this browser are kept for 30 days, one store per map type.
                    {tileCount === null ? '' : ` ${tileCount} tile${tileCount === 1 ? '' : 's'} stored.`}
                </p>
                <PrimaryButton type="button" onClick={emptyMapCache} disabled={clearing}>
                    {clearing ? 'Emptying cache…' : 'Empty map cache'}
                </PrimaryButton>
            </section>
        </AppLayout>
    );
}
