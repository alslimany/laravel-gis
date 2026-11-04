import { useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, Panel, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';

const GEOMETRIES = ['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'];

type ProjectOption = { id: number; name: string };

function tableNameFrom(name: string) {
    const slug = name
        .trim()
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 48);

    if (!slug) {
        return `layer_${Date.now().toString(36)}`;
    }

    return /^[0-9]/.test(slug) ? `layer_${slug}` : slug;
}

export default function Create({ projects = [] }: { projects?: ProjectOption[] }) {
    const { t } = useTranslation();
    const form = useForm({
        name: '',
        description: '',
        project_id: '',
        table_name: '',
        geometry_type: '',
    });
    const [tableTouched, setTableTouched] = useState(false);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post('/layers');
    }

    return (
        <AppLayout title={t('layers.new')}>
            <PageHeader title={t('layers.new')} action={<GhostLink href="/layers">{t('actions.back')}</GhostLink>} />
            <Panel className="max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <p className="max-w-prose text-sm text-muted-foreground">
                        {t('layers.create_hint')}
                    </p>
                    <Field label={t('solution_packs.layer_name')} error={form.errors.name}>
                        <TextInput
                            value={form.data.name}
                            required
                            onChange={(event) => {
                                const name = event.target.value;
                                form.setData('name', name);
                                if (!tableTouched) {
                                    form.setData('table_name', tableNameFrom(name));
                                }
                            }}
                        />
                    </Field>
                    <Field label={t('forms.description')} error={form.errors.description}>
                        <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                    </Field>
                    <Field label={t('layers.project')} error={form.errors.project_id}>
                        <Select value={form.data.project_id} onChange={(event) => form.setData('project_id', event.target.value)}>
                            <option value="">No project</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label={t('layers.table_name')} error={form.errors.table_name} hint={t('layers.table_name_hint')}>
                        <TextInput
                            value={form.data.table_name}
                            required
                            onChange={(event) => {
                                setTableTouched(true);
                                form.setData('table_name', event.target.value);
                            }}
                        />
                    </Field>
                    <Field label={t('layers.geometry_type')} error={form.errors.geometry_type} hint={t('layers.geometry_type_hint')}>
                        <Select value={form.data.geometry_type} onChange={(event) => form.setData('geometry_type', event.target.value)}>
                            <option value="">{t('layers.auto_detect')}</option>
                            {GEOMETRIES.map((type) => (
                                <option key={type} value={type}>
                                    {enumLabel(type)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="flex gap-3">
                        <GhostLink href="/layers">{t('actions.cancel')}</GhostLink>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? t('actions.creating') : t('layers.create')}
                        </PrimaryButton>
                    </div>
                </form>
            </Panel>
        </AppLayout>
    );
}
