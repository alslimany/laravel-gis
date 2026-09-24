import { useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, Heading, PageHeader, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';

const TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'date'];

export default function Editor({ form: record = null, layers = [] }) {
    const editing = Boolean(record);
    const form = useForm({
        name: record?.name || '',
        description: record?.description || '',
        layer_id: record?.layer_id || '',
        is_public: Boolean(record?.is_public),
        schema: record?.schema?.length ? record.schema : [{ name: '', label: '', type: 'text', required: false }],
    });

    function updateField(index, key, value) {
        const schema = form.data.schema.map((field, i) => (i === index ? { ...field, [key]: value } : field));
        form.setData('schema', schema);
    }

    function submit(event) {
        event.preventDefault();
        if (editing) form.put(`/forms/${record.id}`);
        else form.post('/forms');
    }

    return (
        <AppLayout title={editing ? `Edit ${record.name}` : 'New form'}>
            <PageHeader title={editing ? `Edit ${record.name}` : 'New form'} />
            <form onSubmit={submit} className="max-w-3xl space-y-4">
                <Field label="Form name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Description">
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <Field label="Target layer" error={form.errors.layer_id}>
                    <Select value={form.data.layer_id} required onChange={(event) => form.setData('layer_id', event.target.value)}>
                        <option value="">Select layer</option>
                        {layers.map((layer) => (
                            <option key={layer.id} value={layer.id}>
                                {layer.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.is_public} onChange={(event) => form.setData('is_public', event.target.checked)} />
                    Public shareable link
                </label>
                <Heading as="h2" >Fields</Heading>
                {form.data.schema.map((field, index) => (
                    <div key={index} className="grid gap-2 border border-border p-3 sm:grid-cols-4">
                        <TextInput placeholder="column" value={field.name || ''} onChange={(event) => updateField(index, 'name', event.target.value)} />
                        <TextInput placeholder="Label" value={field.label || ''} onChange={(event) => updateField(index, 'label', event.target.value)} />
                        <Select value={field.type || 'text'} onChange={(event) => updateField(index, 'type', event.target.value)}>
                            {TYPES.map((type) => (
                                <option key={type}>{type}</option>
                            ))}
                        </Select>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={Boolean(field.required)} onChange={(event) => updateField(index, 'required', event.target.checked)} />
                            Required
                        </label>
                    </div>
                ))}
                <button
                    type="button"
                    className="font-medium text-link"
                    onClick={() => form.setData('schema', [...form.data.schema, { name: '', label: '', type: 'text', required: false }])}
                >
                    Add field
                </button>
                <div className="flex gap-3">
                    <GhostLink href={editing ? `/forms/${record.id}` : '/forms'}>Cancel</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Update form' : 'Create form'}
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
