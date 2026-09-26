import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import PublicLayout from '@/layouts/public-layout';
import { Field, Flash, Heading, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';
import LocationPicker from '@/Pages/Forms/LocationPicker';

export default function Public({ form, requiresGeometry = false }) {
    const fields = form.schema || [];
    const [includeLocation, setIncludeLocation] = useState(Boolean(requiresGeometry));
    const formState = useForm({
        attributes: {},
        latitude: '',
        longitude: '',
        wkt: '',
        attachment: null,
    });

    const showLocation = Boolean(requiresGeometry) || includeLocation;
    const attributes = formState.data.attributes || {};

    function setAttribute(name, value) {
        formState.setData('attributes', { ...formState.data.attributes, [name]: value });
    }

    function submit(event) {
        event.preventDefault();
        const visibleAttributes = {};
        for (const field of fields) {
            if (!field.name || !isFieldVisible(field, attributes)) {
                continue;
            }
            if (Object.prototype.hasOwnProperty.call(attributes, field.name)) {
                visibleAttributes[field.name] = attributes[field.name];
            }
        }
        formState.transform((data) => ({ ...data, attributes: visibleAttributes }));
        formState.post(`/f/${form.share_token}`, { forceFormData: true });
    }

    return (
        <PublicLayout title={form.name}>
            <form onSubmit={submit} className="mx-auto max-w-xl space-y-4">
                <Heading as="h1">{form.name}</Heading>
                <p className="text-muted-foreground">{form.description}</p>
                <Flash />
                {fields.map((field) =>
                    field.name && isFieldVisible(field, attributes) ? (
                        <Field key={field.name} label={field.label || field.name} error={formState.errors[`attributes.${field.name}`]}>
                            {field.type === 'textarea' ? (
                                <TextArea
                                    required={field.required}
                                    value={attributes[field.name] ?? ''}
                                    onChange={(event) => setAttribute(field.name, event.target.value)}
                                />
                            ) : field.type === 'select' ? (
                                <Select
                                    required={field.required}
                                    value={attributes[field.name] ?? ''}
                                    onChange={(event) => setAttribute(field.name, event.target.value)}
                                >
                                    <option value="">Select</option>
                                    {(field.options || []).map((option) => (
                                        <option key={option}>{option}</option>
                                    ))}
                                </Select>
                            ) : field.type === 'checkbox' ? (
                                <input
                                    type="checkbox"
                                    checked={attributes[field.name] === '1' || attributes[field.name] === true}
                                    onChange={(event) => setAttribute(field.name, event.target.checked ? '1' : '0')}
                                />
                            ) : (
                                <TextInput
                                    type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                                    required={field.required}
                                    value={attributes[field.name] ?? ''}
                                    onChange={(event) => setAttribute(field.name, event.target.value)}
                                />
                            )}
                        </Field>
                    ) : null,
                )}
                {!requiresGeometry ? (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={includeLocation}
                            onChange={(event) => {
                                const checked = event.target.checked;
                                setIncludeLocation(checked);
                                if (!checked) {
                                    formState.setData({
                                        ...formState.data,
                                        latitude: '',
                                        longitude: '',
                                        wkt: '',
                                    });
                                }
                            }}
                        />
                        Include a location
                    </label>
                ) : null}
                {showLocation ? (
                    <Field
                        label={requiresGeometry ? 'Location' : 'Location (optional)'}
                        error={formState.errors.wkt || formState.errors.latitude}
                        hint={
                            requiresGeometry
                                ? 'This layer stores geometry. Pick a point on the map or enter latitude and longitude. A WKT value is used when it is filled in.'
                                : 'Leave this blank to submit without a location. A WKT value is used when it is filled in.'
                        }
                    >
                        <div className="grid gap-3">
                            <LocationPicker
                                latitude={formState.data.latitude}
                                longitude={formState.data.longitude}
                                onPick={(latitude, longitude) =>
                                    formState.setData({
                                        ...formState.data,
                                        latitude,
                                        longitude,
                                        wkt: '',
                                    })
                                }
                            />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Latitude" error={formState.errors.latitude}>
                                    <TextInput
                                        value={formState.data.latitude}
                                        inputMode="decimal"
                                        onChange={(event) => formState.setData('latitude', event.target.value)}
                                    />
                                </Field>
                                <Field label="Longitude" error={formState.errors.longitude}>
                                    <TextInput
                                        value={formState.data.longitude}
                                        inputMode="decimal"
                                        onChange={(event) => formState.setData('longitude', event.target.value)}
                                    />
                                </Field>
                            </div>
                            <Field label="Or WKT geometry" error={formState.errors.wkt}>
                                <TextInput
                                    value={formState.data.wkt}
                                    placeholder="POINT(13.19 32.89)"
                                    onChange={(event) => formState.setData('wkt', event.target.value)}
                                />
                            </Field>
                        </div>
                    </Field>
                ) : null}
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

function answerText(value) {
    if (value === true) {
        return '1';
    }
    if (value === false || value === null || value === undefined) {
        return '';
    }

    return String(value).trim();
}

function isFieldVisible(field, attributes) {
    const rule = field.visibility;
    if (!rule?.field || !rule?.operator) {
        return true;
    }

    const matches = answerText(attributes?.[rule.field]) === answerText(rule.value);
    const condition = rule.operator === 'not_equals' ? !matches : matches;

    return rule.action === 'hide' ? !condition : condition;
}
