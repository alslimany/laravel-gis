import { useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, Heading, PageHeader, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';

const TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'date'];
const NON_SPATIAL = new Set(['', 'none', 'table', 'raster', 'unknown']);

function layerIsSpatial(layer) {
    if (!layer?.geometry_type) {
        return false;
    }

    return !NON_SPATIAL.has(String(layer.geometry_type).toLowerCase());
}

export default function Editor({ form: record = null, layers = [] }) {
    const { t } = useTranslation();
    const editing = Boolean(record);
    const form = useForm({
        name: record?.name || '',
        description: record?.description || '',
        layer_mode: record?.layer_id ? 'link' : 'none',
        layer_id: record?.layer_id ? String(record.layer_id) : '',
        collect_geometry: Boolean(record?.collect_geometry || (record?.layer_id && layerIsSpatial(layers.find((layer) => layer.id === record.layer_id)))),
        is_public: Boolean(record?.is_public),
        schema: record?.schema?.length ? record.schema : [{ name: '', label: '', type: 'text', required: false }],
    });

    const selectedLayer = layers.find((layer) => String(layer.id) === String(form.data.layer_id));
    const spatialLink = form.data.layer_mode === 'link' && layerIsSpatial(selectedLayer);

    function updateField(index, key, value) {
        const schema = form.data.schema.map((field, i) => (i === index ? { ...field, [key]: value } : field));
        form.setData('schema', schema);
    }

    function setVisibility(index, action) {
        if (action === 'always') {
            updateField(index, 'visibility', null);
            return;
        }

        const current = form.data.schema[index]?.visibility || {};
        updateField(index, 'visibility', {
            action,
            field: current.field || '',
            operator: current.operator || 'equals',
            value: current.value || '',
        });
    }

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            description: data.description,
            is_public: data.is_public,
            layer_mode: data.layer_mode,
            collect_geometry: spatialLink ? true : data.collect_geometry,
            create_layer: data.layer_mode === 'create',
            layer_id: data.layer_mode === 'link' && data.layer_id ? data.layer_id : null,
            schema: data.schema,
        }));
        if (editing) form.put(`/forms/${record.id}`);
        else form.post('/forms');
    }

    return (
        <AppLayout title={editing ? __('forms.ed.edit_name', { name: record.name }) : __('forms.ed.new')}>
            <PageHeader title={editing ? __('forms.ed.edit_name', { name: record.name }) : __('forms.ed.new')} />
            <form onSubmit={submit} className="max-w-3xl space-y-4">
                <Field label={t('solution_packs.form_name')} error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label={t('forms.description')}>
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <Field
                    label={__('forms.ed.layer')}
                    error={form.errors.layer_id || form.errors.schema}
                    hint={__('forms.ed.layer_hint')}
                >
                    <Select
                        value={form.data.layer_mode}
                        onChange={(event) => {
                            const layer_mode = event.target.value;
                            form.setData({
                                ...form.data,
                                layer_mode,
                                layer_id: layer_mode === 'link' ? form.data.layer_id : '',
                            });
                        }}
                    >
                        <option value="none">{__('forms.ed.standalone')}</option>
                        <option value="link">{__('forms.ed.link_layer')}</option>
                        <option value="create">{t('layers.create_from_fields')}</option>
                    </Select>
                </Field>
                {form.data.layer_mode === 'link' ? (
                    <Field label={t('imports.existing_layer')} error={form.errors.layer_id}>
                        <Select
                            value={form.data.layer_id}
                            onChange={(event) => {
                                const layer_id = event.target.value;
                                const layer = layers.find((item) => String(item.id) === String(layer_id));
                                form.setData({
                                    ...form.data,
                                    layer_id,
                                    collect_geometry: layerIsSpatial(layer) ? true : form.data.collect_geometry,
                                });
                            }}
                        >
                            <option value="">{t('layers.choose_layer')}</option>
                            {layers.map((layer) => (
                                <option key={layer.id} value={layer.id}>
                                    {layer.name}
                                    {layer.geometry_type ? ` (${enumLabel(layer.geometry_type)})` : ''}
                                </option>
                            ))}
                        </Select>
                    </Field>
                ) : null}
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={spatialLink || form.data.collect_geometry}
                        disabled={spatialLink}
                        onChange={(event) => form.setData('collect_geometry', event.target.checked)}
                    />
                    {__('forms.ed.collect_location')}
                </label>
                <p className="text-xs text-muted-foreground">
                    {spatialLink
                        ? __('forms.ed.geom_required')
                        : form.data.layer_mode === 'create'
                          ? __('forms.ed.geom_create')
                          : __('forms.ed.geom_off')}
                </p>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.is_public} onChange={(event) => form.setData('is_public', event.target.checked)} />
                    {__('forms.ed.public_link')}
                </label>
                <Heading as="h2">{t('layers.fields')}</Heading>
                <p className="text-xs text-muted-foreground">{__('forms.ed.visibility_help')}</p>
                {form.data.schema.map((field, index) => {
                    const otherFields = form.data.schema.filter((candidate, candidateIndex) => candidateIndex !== index && candidate.name);
                    const visibility = field.visibility?.action === 'hide' || field.visibility?.action === 'show' ? field.visibility : null;

                    return (
                        <div key={index} className="grid gap-2 border border-border p-3">
                            <div className="grid gap-2 sm:grid-cols-4">
                                <TextInput placeholder={__('forms.ed.column')} value={field.name || ''} onChange={(event) => updateField(index, 'name', event.target.value)} />
                                <TextInput placeholder={__('forms.ed.label')} value={field.label || ''} onChange={(event) => updateField(index, 'label', event.target.value)} />
                                <Select value={field.type || 'text'} onChange={(event) => updateField(index, 'type', event.target.value)}>
                                    {TYPES.map((type) => (
                                        <option key={type} value={type}>
                                            {__(`forms.type.${type}`)}
                                        </option>
                                    ))}
                                </Select>
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={Boolean(field.required)} onChange={(event) => updateField(index, 'required', event.target.checked)} />
                                    {__('forms.ed.required')}
                                </label>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-4">
                                <Select value={visibility?.action || 'always'} onChange={(event) => setVisibility(index, event.target.value)} aria-label={__('forms.ed.visibility')}>
                                    <option value="always">{t('disaster.always_visible')}</option>
                                    <option value="show">{__('forms.ed.show_when')}</option>
                                    <option value="hide">{__('forms.ed.hide_when')}</option>
                                </Select>
                                {visibility ? (
                                    <>
                                        <Select
                                            value={visibility.field || ''}
                                            aria-label={t('common.condition_field')}
                                            onChange={(event) => updateField(index, 'visibility', { ...visibility, field: event.target.value })}
                                        >
                                            <option value="">{t('common.field')}</option>
                                            {otherFields.map((candidate) => (
                                                <option key={candidate.name} value={candidate.name}>
                                                    {candidate.label || candidate.name}
                                                </option>
                                            ))}
                                        </Select>
                                        <Select
                                            value={visibility.operator || 'equals'}
                                            aria-label={t('common.condition')}
                                            onChange={(event) => updateField(index, 'visibility', { ...visibility, operator: event.target.value })}
                                        >
                                            <option value="equals">{__('forms.ed.equals')}</option>
                                            <option value="not_equals">{__('forms.ed.not_equals')}</option>
                                        </Select>
                                        <TextInput
                                            placeholder={__('forms.ed.value')}
                                            aria-label={t('common.condition_value')}
                                            value={visibility.value || ''}
                                            onChange={(event) => updateField(index, 'visibility', { ...visibility, value: event.target.value })}
                                        />
                                    </>
                                ) : null}
                            </div>
                            {form.errors[`schema.${index}.visibility.field`] ? (
                                <p className="text-xs text-destructive">{form.errors[`schema.${index}.visibility.field`]}</p>
                            ) : null}
                        </div>
                    );
                })}
                <button
                    type="button"
                    className="font-medium text-primary hover:underline"
                    onClick={() => form.setData('schema', [...form.data.schema, { name: '', label: '', type: 'text', required: false }])}
                >
                    {__('forms.ed.add_field')}
                </button>
                <div className="flex gap-3">
                    <GhostLink href={editing ? `/forms/${record.id}` : '/forms'}>{t('actions.cancel')}</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? __('forms.ed.update') : __('forms.ed.create')}
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
