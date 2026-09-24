import { useForm } from '@inertiajs/react';
import PublicLayout from '@/layouts/public-layout';
import { Field, Flash, Heading, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';

export default function Public({ form }) {
    const fields = form.schema || [];
    const formState = useForm({
        attributes: {},
        latitude: '',
        longitude: '',
        wkt: '',
        attachment: null,
    });

    function setAttribute(name, value) {
        formState.setData('attributes', { ...formState.data.attributes, [name]: value });
    }

    function submit(event) {
        event.preventDefault();
        formState.post(`/f/${form.share_token}`, { forceFormData: true });
    }

    return (
        <PublicLayout title={form.name}>
            <form onSubmit={submit} className="mx-auto max-w-xl space-y-4">
                <Heading as="h1">{form.name}</Heading>
                <p className="text-muted-foreground">{form.description}</p>
                <Flash />
                {fields.map((field) =>
                    field.name ? (
                        <Field key={field.name} label={field.label || field.name} error={formState.errors[`attributes.${field.name}`]}>
                            {field.type === 'textarea' ? (
                                <TextArea required={field.required} onChange={(event) => setAttribute(field.name, event.target.value)} />
                            ) : field.type === 'select' ? (
                                <Select required={field.required} onChange={(event) => setAttribute(field.name, event.target.value)}>
                                    <option value="">Select</option>
                                    {(field.options || []).map((option) => (
                                        <option key={option}>{option}</option>
                                    ))}
                                </Select>
                            ) : field.type === 'checkbox' ? (
                                <input type="checkbox" onChange={(event) => setAttribute(field.name, event.target.checked ? '1' : '0')} />
                            ) : (
                                <TextInput
                                    type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                                    required={field.required}
                                    onChange={(event) => setAttribute(field.name, event.target.value)}
                                />
                            )}
                        </Field>
                    ) : null,
                )}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Latitude">
                        <TextInput value={formState.data.latitude} onChange={(event) => formState.setData('latitude', event.target.value)} />
                    </Field>
                    <Field label="Longitude">
                        <TextInput value={formState.data.longitude} onChange={(event) => formState.setData('longitude', event.target.value)} />
                    </Field>
                </div>
                <Field label="Or WKT geometry">
                    <TextInput value={formState.data.wkt} placeholder="POINT(46.67 24.71)" onChange={(event) => formState.setData('wkt', event.target.value)} />
                </Field>
                <Field label="Attachment">
                    <input type="file" onChange={(event) => formState.setData('attachment', event.target.files?.[0] || null)} />
                </Field>
                <PrimaryButton type="submit" disabled={formState.processing}>
                    Submit
                </PrimaryButton>
            </form>
        </PublicLayout>
    );
}
