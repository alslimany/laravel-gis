import { useForm } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import { type FormEvent, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, Panel, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

type PackField = {
    name: string;
    alias?: string;
    type: string;
    required?: boolean;
    domain?: string[];
    calculated?: string;
};

type PackLayer = {
    name: string;
    description?: string;
    geometry_type: string;
    style?: { renderer?: string; symbol?: Record<string, unknown> };
    fields: PackField[];
};

type PackRelationship = { name: string; parent: string; child: string; foreign_key: string };

type PackSampleFeature = { layer: string; attributes?: Record<string, unknown>; wkt?: string; refs?: Record<string, unknown> };

type PackMap = {
    name: string;
    description?: string;
    basemap: string;
    viewport?: { center?: [number, number]; zoom?: number; rotation?: number };
    layers: string[];
};

type PackSchemaField = { name: string; label?: string; type: string; required?: boolean; options?: string[] };

type PackForm = {
    name: string;
    description?: string;
    layer: string;
    is_public?: boolean;
    schema: PackSchemaField[];
};

type PackWidget = { type: string; title: string; layer?: string; group_by?: string; aggregation?: string };

type PackDashboard = { name: string; description?: string; widgets: PackWidget[] };

type PackWorkflowStep = { title: string; description?: string; kind: 'form' | 'dashboard' | 'map' | 'text'; target?: string };

type Definition = {
    layers: PackLayer[];
    relationships: PackRelationship[];
    features: PackSampleFeature[];
    maps: PackMap[];
    forms: PackForm[];
    dashboards: PackDashboard[];
    workflow: PackWorkflowStep[];
};

type PackProp = {
    id: number;
    slug: string;
    name: string;
    description?: string | null;
    definition: Partial<Definition>;
};

const geometryOptions: Array<[string, string]> = [
    ['Polygon', 'enum.polygon'],
    ['LineString', 'enum.linestring'],
    ['Point', 'enum.point'],
    ['MultiPolygon', 'enum.multipolygon'],
    ['None', 'sp.opt.no_geometry'],
];

const fieldTypeOptions: Array<[string, string]> = [
    ['string', 'sp.opt.text'],
    ['integer', 'sp.opt.integer'],
    ['float', 'sp.opt.decimal'],
    ['boolean', 'sp.opt.boolean'],
    ['date', 'sp.opt.date'],
    ['datetime', 'sp.opt.datetime'],
];

const schemaTypeOptions: Array<[string, string]> = [
    ['text', 'sp.opt.text'],
    ['textarea', 'sp.opt.long_text'],
    ['number', 'sp.opt.number'],
    ['select', 'sp.opt.choice'],
    ['checkbox', 'sp.opt.checkbox'],
    ['date', 'sp.opt.date'],
];

function widgetTypeOptions(t: (key: string) => string): Array<[string, string]> {
    return [
        ['indicator', 'dash.type.indicator'],
        ['serial', 'sp.opt.serial'],
        ['pie', 'dash.type.pie'],
        ['table', 'layers.table'],
        ['list', 'dash.type.list'],
        ['map', 'dash.type.map'],
        ['text', 'sp.opt.text'],
        ['category', 'sp.opt.category'],
    ];
}

const aggregationOptions: Array<[string, string]> = [
    ['count', 'dash.agg_count'],
    ['sum', 'dash.agg_sum'],
    ['avg', 'dash.agg_avg'],
];

const basemapOptions: Array<[string, string]> = [
    ['osm', 'sp.opt.street'],
    ['imagery', 'dash.satellite'],
];

function slugify(value: string): string {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
}

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

const emptyDefinition: Definition = {
    layers: [],
    relationships: [],
    features: [],
    maps: [],
    forms: [],
    dashboards: [],
    workflow: [],
};

function defaultLayer(): PackLayer {
    return { name: '', description: '', geometry_type: 'Polygon', fields: [] };
}

function defaultField(): PackField {
    return { name: '', alias: '', type: 'string', required: false, domain: [], calculated: '' };
}

function defaultRelationship(): PackRelationship {
    return { name: '', parent: '', child: '', foreign_key: '' };
}

function defaultMap(): PackMap {
    return {
        name: __('sp.site_overview'),
        description: '',
        basemap: 'osm',
        viewport: { center: [-122.4195, 37.7745], zoom: 14, rotation: 0 },
        layers: [],
    };
}

function defaultSchemaField(): PackSchemaField {
    return { name: '', label: '', type: 'text', required: false, options: [] };
}

function defaultForm(): PackForm {
    return { name: '', description: '', layer: '', is_public: true, schema: [] };
}

function defaultWidget(): PackWidget {
    return { type: 'indicator', title: '', layer: '', group_by: '', aggregation: 'count' };
}

function defaultDashboard(): PackDashboard {
    return { name: '', description: '', widgets: [] };
}

function defaultWorkflowStep(): PackWorkflowStep {
    return { title: '', description: '', kind: 'text', target: '' };
}

export default function Edit({ pack }: { pack?: PackProp | null }) {
    const { t } = useTranslation();
    const isEdit = Boolean(pack);
    const form = useForm({
        name: pack?.name ?? '',
        slug: pack?.slug ?? '',
        description: pack?.description ?? '',
        definition: { ...emptyDefinition, ...(pack?.definition ?? {}) } as Definition,
    });
    const [slugTouched, setSlugTouched] = useState(isEdit);

    const definition = form.data.definition;
    const layerNames = definition.layers.map((layer) => layer.name).filter((name) => name !== '');

    function editDefinition(mutate: (draft: Definition) => void) {
        const draft = clone(form.data.definition);
        mutate(draft);
        form.setData('definition', draft);
    }

    function setName(value: string) {
        const patch: { name: string; slug?: string } = { name: value };
        if (!slugTouched) {
            patch.slug = slugify(value);
        }
        form.setData(patch);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            slug: data.slug || slugify(data.name),
            description: data.description,
            definition: data.definition,
        }));
        if (pack) {
            form.put(`/solution-packs/${pack.id}`);
        } else {
            form.post('/solution-packs');
        }
    }

    return (
        <AppLayout title={isEdit ? __('sp.edit') : __('sp.new')}>
            <PageHeader
                title={isEdit ? __('sp.edit_name', { name: pack?.name ?? '' }) : __('sp.new')}
                action={<GhostLink href="/solution-packs">{t('solution_packs.back_packs')}</GhostLink>}
            />
            <p className="max-w-2xl text-sm text-base-600">
                {__('sp.intro')}
            </p>

            <form onSubmit={submit} className="grid max-w-4xl gap-4">
                <Panel className="grid gap-3">
                    <h2 className="text-sm font-semibold">{t('solution_packs.basics')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('solution_packs.pack_name')} error={form.errors.name}>
                            <TextInput
                                value={form.data.name}
                                required
                                onChange={(event) => setName(event.target.value)}
                            />
                        </Field>
                        <Field label={t('solution_packs.slug')} error={form.errors.slug} hint={t('solution_packs.slug_hint')}>
                            <TextInput
                                value={form.data.slug}
                                required
                                onChange={(event) => {
                                    setSlugTouched(true);
                                    form.setData('slug', event.target.value.toLowerCase());
                                }}
                            />
                        </Field>
                    </div>
                    <Field label={t('forms.description')} error={form.errors.description}>
                        <TextArea
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>
                </Panel>

                <Panel className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold">{t('layers.title')}</h2>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                editDefinition((draft) => {
                                    draft.layers.push(defaultLayer());
                                })
                            }
                        >
                            {__('sp.add_layer')}
                        </Button>
                    </div>
                    {definition.layers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{__('sp.no_layers')}</p>
                    ) : null}
                    {definition.layers.map((layer, layerIndex) => (
                        <div key={layerIndex} className="grid gap-3 rounded-md border border-border p-3">
                            <div className="flex items-end justify-between gap-2">
                                <div className="grid flex-1 gap-3 sm:grid-cols-3">
                                    <Field label={t('solution_packs.layer_name')} error={form.errors[`definition.layers.${layerIndex}.name`]}>
                                        <TextInput
                                            value={layer.name}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.layers[layerIndex].name = event.target.value;
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label={t('layers.geometry')}
                                        error={form.errors[`definition.layers.${layerIndex}.geometry_type`]}
                                    >
                                        <Select
                                            value={layer.geometry_type}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.layers[layerIndex].geometry_type = event.target.value;
                                                })
                                            }
                                        >
                                            {geometryOptions.map(([value, label]) => (
                                                <option key={value} value={value}>
                                                    {__(label)}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label={t('forms.description')}>
                                        <TextInput
                                            value={layer.description ?? ''}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.layers[layerIndex].description = event.target.value;
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() =>
                                        editDefinition((draft) => {
                                            draft.layers.splice(layerIndex, 1);
                                        })
                                    }
                                >
                                    {__('sp.remove')}
                                </Button>
                            </div>

                            <details className="text-sm">
                                <summary className="cursor-pointer text-muted-foreground">{t('layers.default_style')}</summary>
                                <div className="mt-3 grid gap-3 sm:grid-cols-4">
                                    <Field label={t('solution_packs.fill_color')}>
                                        <TextInput
                                            type="color"
                                            value={String(layer.style?.symbol?.fillColor ?? '#3b82f6')}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    const target = draft.layers[layerIndex];
                                                    target.style = {
                                                        renderer: 'simple',
                                                        symbol: {
                                                            ...(target.style?.symbol ?? {}),
                                                            fillColor: event.target.value,
                                                        },
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label={t('solution_packs.fill_opacity')}>
                                        <TextInput
                                            type="number"
                                            step="0.05"
                                            min="0"
                                            max="1"
                                            value={String(layer.style?.symbol?.fillOpacity ?? 0.4)}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    const target = draft.layers[layerIndex];
                                                    target.style = {
                                                        renderer: 'simple',
                                                        symbol: {
                                                            ...(target.style?.symbol ?? {}),
                                                            fillOpacity: Number(event.target.value),
                                                        },
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label={t('solution_packs.stroke_color')}>
                                        <TextInput
                                            type="color"
                                            value={String(layer.style?.symbol?.strokeColor ?? '#1d4ed8')}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    const target = draft.layers[layerIndex];
                                                    target.style = {
                                                        renderer: 'simple',
                                                        symbol: {
                                                            ...(target.style?.symbol ?? {}),
                                                            strokeColor: event.target.value,
                                                        },
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label={t('solution_packs.stroke_width')}>
                                        <TextInput
                                            type="number"
                                            step="1"
                                            min="0"
                                            value={String(layer.style?.symbol?.strokeWidth ?? 2)}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    const target = draft.layers[layerIndex];
                                                    target.style = {
                                                        renderer: 'simple',
                                                        symbol: {
                                                            ...(target.style?.symbol ?? {}),
                                                            strokeWidth: Number(event.target.value),
                                                        },
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                            </details>

                            <div className="flex items-center justify-between">
                                <h3 className="text-xs font-semibold uppercase text-muted-foreground">{t('layers.fields')}</h3>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        editDefinition((draft) => {
                                            draft.layers[layerIndex].fields.push(defaultField());
                                        })
                                    }
                                >
                                    {__('sp.add_field')}
                                </Button>
                            </div>
                            {layer.fields.map((field, fieldIndex) => {
                                const base = `definition.layers.${layerIndex}.fields.${fieldIndex}`;

                                return (
                                    <div key={fieldIndex} className="grid items-end gap-2 border-t border-border pt-2 sm:grid-cols-12">
                                        <div className="sm:col-span-3">
                                            <Field label={t('forms.name')} error={form.errors[`${base}.name`]}>
                                                <TextInput
                                                    value={field.name}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.layers[layerIndex].fields[fieldIndex].name = event.target.value;
                                                        })
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Field label={t('forms.label')}>
                                                <TextInput
                                                    value={field.alias ?? ''}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.layers[layerIndex].fields[fieldIndex].alias = event.target.value;
                                                        })
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Field label={t('forms.type')} error={form.errors[`${base}.type`]}>
                                                <Select
                                                    value={field.type}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.layers[layerIndex].fields[fieldIndex].type = event.target.value;
                                                        })
                                                    }
                                                >
                                                    {fieldTypeOptions.map(([value, label]) => (
                                                        <option key={value} value={value}>
                                                            {__(label)}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-3">
                                            <Field label={t('solution_packs.allowed_values')} hint={t('solution_packs.allowed_values_hint')}>
                                                <TextInput
                                                    value={(field.domain ?? []).join(', ')}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.layers[layerIndex].fields[fieldIndex].domain = event.target.value
                                                                .split(',')
                                                                .map((value) => value.trim())
                                                                .filter((value) => value !== '');
                                                        })
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-1">
                                            <label className="flex h-9 items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={Boolean(field.required)}
                                                    onCheckedChange={(checked) =>
                                                        editDefinition((draft) => {
                                                            draft.layers[layerIndex].fields[fieldIndex].required = checked === true;
                                                        })
                                                    }
                                                />
                                                {__('sp.req')}
                                            </label>
                                        </div>
                                        <div className="sm:col-span-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                onClick={() =>
                                                    editDefinition((draft) => {
                                                        draft.layers[layerIndex].fields.splice(fieldIndex, 1);
                                                    })
                                                }
                                            >
                                                ×
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </Panel>

                <Panel className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold">{t('relationships.title')}</h2>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                editDefinition((draft) => {
                                    draft.relationships.push(defaultRelationship());
                                })
                            }
                        >
                            {__('sp.add_relationship')}
                        </Button>
                    </div>
                    {definition.relationships.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{__('sp.no_relationships')}</p>
                    ) : null}
                    {definition.relationships.map((relationship, index) => {
                        const base = `definition.relationships.${index}`;

                        return (
                            <div key={index} className="grid items-end gap-2 border-t border-border pt-2 sm:grid-cols-12">
                                <div className="sm:col-span-3">
                                    <Field label={t('forms.name')} error={form.errors[`${base}.name`]}>
                                        <TextInput
                                            value={relationship.name}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.relationships[index].name = event.target.value;
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="sm:col-span-2">
                                    <Field label={t('solution_packs.parent')} error={form.errors[`${base}.parent`]}>
                                        <Select
                                            value={relationship.parent}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.relationships[index].parent = event.target.value;
                                                })
                                            }
                                        >
                                            <option value="">{t('common.choose_placeholder')}</option>
                                            {layerNames.map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                                <div className="sm:col-span-2">
                                    <Field label={t('solution_packs.child')} error={form.errors[`${base}.child`]}>
                                        <Select
                                            value={relationship.child}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.relationships[index].child = event.target.value;
                                                })
                                            }
                                        >
                                            <option value="">{t('common.choose_placeholder')}</option>
                                            {layerNames.map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                                <div className="sm:col-span-3">
                                    <Field label={t('solution_packs.foreign_key')} error={form.errors[`${base}.foreign_key`]}>
                                        <TextInput
                                            value={relationship.foreign_key}
                                            placeholder="parent_id"
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.relationships[index].foreign_key = event.target.value;
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="sm:col-span-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() =>
                                            editDefinition((draft) => {
                                                draft.relationships.splice(index, 1);
                                            })
                                        }
                                    >
                                        {__('sp.remove')}
                                    </Button>
                                </div>
                            </div>
                        );
                    })}
                    {definition.features.length > 0 ? (
                        <p className="text-xs text-muted-foreground">
                            {definition.features.length} sample features are preserved from the original pack and will be
                            seeded when this pack is installed.
                        </p>
                    ) : null}
                </Panel>

                <Panel className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold">{__('sp.starter_map')}</h2>
                        {definition.maps.length === 0 ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    editDefinition((draft) => {
                                        draft.maps.push(defaultMap());
                                    })
                                }
                            >
                                {__('sp.add_map')}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() =>
                                    editDefinition((draft) => {
                                        draft.maps = [];
                                    })
                                }
                            >
                                {__('sp.remove')}
                            </Button>
                        )}
                    </div>
                    {definition.maps.map((map, index) => {
                        const base = `definition.maps.${index}`;
                        const viewport = map.viewport ?? { center: [0, 0], zoom: 2, rotation: 0 };

                        return (
                            <div key={index} className="grid gap-3 rounded-md border border-border p-3">
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Field label={t('solution_packs.map_name')} error={form.errors[`${base}.name`]}>
                                        <TextInput
                                            value={map.name}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.maps[index].name = event.target.value;
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label={t('solution_packs.basemap')}>
                                        <Select
                                            value={map.basemap}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.maps[index].basemap = event.target.value;
                                                })
                                            }
                                        >
                                            {basemapOptions.map(([value, label]) => (
                                                <option key={value} value={value}>
                                                    {__(label)}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label={t('solution_packs.zoom')}>
                                        <TextInput
                                            type="number"
                                            min="1"
                                            max="20"
                                            value={String(viewport.zoom ?? 2)}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.maps[index].viewport = {
                                                        ...(draft.maps[index].viewport ?? {}),
                                                        center: draft.maps[index].viewport?.center ?? [0, 0],
                                                        zoom: Number(event.target.value),
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label={t('solution_packs.center_longitude')}>
                                        <TextInput
                                            type="number"
                                            step="0.0001"
                                            value={String(viewport.center?.[0] ?? 0)}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.maps[index].viewport = {
                                                        ...(draft.maps[index].viewport ?? {}),
                                                        zoom: draft.maps[index].viewport?.zoom ?? 2,
                                                        center: [Number(event.target.value), viewport.center?.[1] ?? 0],
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label={t('solution_packs.center_latitude')}>
                                        <TextInput
                                            type="number"
                                            step="0.0001"
                                            value={String(viewport.center?.[1] ?? 0)}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.maps[index].viewport = {
                                                        ...(draft.maps[index].viewport ?? {}),
                                                        zoom: draft.maps[index].viewport?.zoom ?? 2,
                                                        center: [viewport.center?.[0] ?? 0, Number(event.target.value)],
                                                    };
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                                <div>
                                    <p className="mb-2 text-xs font-semibold uppercase text-muted-foreground">{__('sp.layers_on_map')}</p>
                                    <div className="flex flex-wrap gap-4">
                                        {layerNames.map((name) => (
                                            <label key={name} className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={map.layers.includes(name)}
                                                    onCheckedChange={(checked) =>
                                                        editDefinition((draft) => {
                                                            const list = draft.maps[index].layers;
                                                            draft.maps[index].layers =
                                                                checked === true
                                                                    ? Array.from(new Set([...list, name]))
                                                                    : list.filter((entry) => entry !== name);
                                                        })
                                                    }
                                                />
                                                {name}
                                            </label>
                                        ))}
                                        {layerNames.length === 0 ? (
                                            <span className="text-sm text-muted-foreground">{t('layers.add_layers_first')}</span>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </Panel>

                <Panel className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold">{__('sp.form')}</h2>
                        {definition.forms.length === 0 ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    editDefinition((draft) => {
                                        draft.forms.push(defaultForm());
                                    })
                                }
                            >
                                {__('sp.add_form')}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() =>
                                    editDefinition((draft) => {
                                        draft.forms = [];
                                    })
                                }
                            >
                                {__('sp.remove')}
                            </Button>
                        )}
                    </div>
                    {definition.forms.map((formDef, index) => {
                        const base = `definition.forms.${index}`;

                        return (
                            <div key={index} className="grid gap-3 rounded-md border border-border p-3">
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Field label={t('solution_packs.form_name')} error={form.errors[`${base}.name`]}>
                                        <TextInput
                                            value={formDef.name}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.forms[index].name = event.target.value;
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label={__('sp.layer')} error={form.errors[`${base}.layer`]}>
                                        <Select
                                            value={formDef.layer}
                                            onChange={(event) =>
                                                editDefinition((draft) => {
                                                    draft.forms[index].layer = event.target.value;
                                                })
                                            }
                                        >
                                            <option value="">{t('common.choose_placeholder')}</option>
                                            {layerNames.map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <label className="flex h-9 items-center gap-2 self-end text-sm">
                                        <Checkbox
                                            checked={formDef.is_public !== false}
                                            onCheckedChange={(checked) =>
                                                editDefinition((draft) => {
                                                    draft.forms[index].is_public = checked === true;
                                                })
                                            }
                                        />
                                        {__('sp.public_link')}
                                    </label>
                                </div>

                                <div className="flex items-center justify-between">
                                    <h3 className="text-xs font-semibold uppercase text-muted-foreground">{t('layers.fields')}</h3>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            editDefinition((draft) => {
                                                draft.forms[index].schema.push(defaultSchemaField());
                                            })
                                        }
                                    >
                                        {__('sp.add_field')}
                                    </Button>
                                </div>
                                {formDef.schema.map((field, fieldIndex) => {
                                    const fieldBase = `${base}.schema.${fieldIndex}`;

                                    return (
                                        <div key={fieldIndex} className="grid items-end gap-2 border-t border-border pt-2 sm:grid-cols-12">
                                            <div className="sm:col-span-3">
                                                <Field label={t('solution_packs.column')} error={form.errors[`${fieldBase}.name`]}>
                                                    <TextInput
                                                        value={field.name}
                                                        placeholder="result"
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.forms[index].schema[fieldIndex].name = event.target.value;
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-3">
                                                <Field label={t('forms.label')}>
                                                    <TextInput
                                                        value={field.label ?? ''}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.forms[index].schema[fieldIndex].label = event.target.value;
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Field label={t('forms.type')} error={form.errors[`${fieldBase}.type`]}>
                                                    <Select
                                                        value={field.type}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.forms[index].schema[fieldIndex].type = event.target.value;
                                                            })
                                                        }
                                                    >
                                                        {schemaTypeOptions.map(([value, label]) => (
                                                            <option key={value} value={value}>
                                                                {__(label)}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-3">
                                                <Field label={__('sp.options')} hint={t('solution_packs.allowed_values_hint')}>
                                                    <TextInput
                                                        value={(field.options ?? []).join(', ')}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.forms[index].schema[fieldIndex].options = event.target.value
                                                                    .split(',')
                                                                    .map((value) => value.trim())
                                                                    .filter((value) => value !== '');
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-1">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    onClick={() =>
                                                        editDefinition((draft) => {
                                                            draft.forms[index].schema.splice(fieldIndex, 1);
                                                        })
                                                    }
                                                >
                                                    ×
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </Panel>

                <Panel className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold">{t('dashboard.title')}</h2>
                        {definition.dashboards.length === 0 ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    editDefinition((draft) => {
                                        draft.dashboards.push(defaultDashboard());
                                    })
                                }
                            >
                                {__('sp.add_dashboard')}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() =>
                                    editDefinition((draft) => {
                                        draft.dashboards = [];
                                    })
                                }
                            >
                                {__('sp.remove')}
                            </Button>
                        )}
                    </div>
                    {definition.dashboards.map((dashboard, index) => {
                        const base = `definition.dashboards.${index}`;

                        return (
                            <div key={index} className="grid gap-3 rounded-md border border-border p-3">
                                <Field label={__('sp.dashboard_name')} error={form.errors[`${base}.name`]}>
                                    <TextInput
                                        value={dashboard.name}
                                        onChange={(event) =>
                                            editDefinition((draft) => {
                                                draft.dashboards[index].name = event.target.value;
                                            })
                                        }
                                    />
                                </Field>

                                <div className="flex items-center justify-between">
                                    <h3 className="text-xs font-semibold uppercase text-muted-foreground">{__('sp.widgets')}</h3>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            editDefinition((draft) => {
                                                draft.dashboards[index].widgets.push(defaultWidget());
                                            })
                                        }
                                    >
                                        {__('sp.add_widget')}
                                    </Button>
                                </div>
                                {dashboard.widgets.map((widget, widgetIndex) => {
                                    const widgetBase = `${base}.widgets.${widgetIndex}`;

                                    return (
                                        <div key={widgetIndex} className="grid items-end gap-2 border-t border-border pt-2 sm:grid-cols-12">
                                            <div className="sm:col-span-3">
                                                <Field label={t('forms.type')} error={form.errors[`${widgetBase}.type`]}>
                                                    <Select
                                                        value={widget.type}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.dashboards[index].widgets[widgetIndex].type = event.target.value;
                                                            })
                                                        }
                                                    >
                                                        {widgetTypeOptions(t).map(([value, label]) => (
                                                            <option key={value} value={value}>
                                                                {__(label)}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-3">
                                                <Field label={t('forms.title')}>
                                                    <TextInput
                                                        value={widget.title}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.dashboards[index].widgets[widgetIndex].title = event.target.value;
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Field label={__('sp.layer')} error={form.errors[`${widgetBase}.layer`]}>
                                                    <Select
                                                        value={widget.layer ?? ''}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.dashboards[index].widgets[widgetIndex].layer = event.target.value;
                                                            })
                                                        }
                                                    >
                                                        <option value="">{__('sp.none')}</option>
                                                        {layerNames.map((name) => (
                                                            <option key={name} value={name}>
                                                                {name}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Field label={t('common.group_by')}>
                                                    <TextInput
                                                        value={widget.group_by ?? ''}
                                                        placeholder="status"
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.dashboards[index].widgets[widgetIndex].group_by = event.target.value;
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-1">
                                                <Field label={__('sp.agg')}>
                                                    <Select
                                                        value={widget.aggregation ?? 'count'}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.dashboards[index].widgets[widgetIndex].aggregation = event.target.value;
                                                            })
                                                        }
                                                    >
                                                        {aggregationOptions.map(([value, label]) => (
                                                            <option key={value} value={value}>
                                                                {__(label)}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                </Field>
                                            </div>
                                            <div className="sm:col-span-1">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    onClick={() =>
                                                        editDefinition((draft) => {
                                                            draft.dashboards[index].widgets.splice(widgetIndex, 1);
                                                        })
                                                    }
                                                >
                                                    ×
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </Panel>

                <Panel className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">{__('sp.runbook')}</h2>
                            <p className="text-xs text-muted-foreground">
                                {__('sp.runbook_help')}
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                editDefinition((draft) => {
                                    draft.workflow.push(defaultWorkflowStep());
                                })
                            }
                        >
                            {__('sp.add_step')}
                        </Button>
                    </div>
                    {definition.workflow.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{__('sp.no_steps')}</p>
                    ) : (
                        <div className="grid gap-3">
                            {definition.workflow.map((step, index) => {
                                const base = `definition.workflow.${index}`;
                                const targetOptions =
                                    step.kind === 'form'
                                        ? definition.forms.map((item) => item.name)
                                        : step.kind === 'dashboard'
                                          ? definition.dashboards.map((item) => item.name)
                                          : step.kind === 'map'
                                            ? definition.maps.map((item) => item.name)
                                            : [];

                                return (
                                    <div key={index} className="grid items-end gap-2 border border-border p-3 sm:grid-cols-12">
                                        <div className="sm:col-span-3">
                                            <Field label={__('sp.step_title')} error={form.errors[`${base}.title`]}>
                                                <TextInput
                                                    value={step.title}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.workflow[index].title = event.target.value;
                                                        })
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Field label={t('forms.type')} error={form.errors[`${base}.kind`]}>
                                                <Select
                                                    value={step.kind}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.workflow[index].kind = event.target
                                                                .value as PackWorkflowStep['kind'];
                                                            draft.workflow[index].target = '';
                                                        })
                                                    }
                                                >
                                                    <option value="text">{__('sp.note')}</option>
                                                    <option value="form">{__('sp.form')}</option>
                                                    <option value="dashboard">{t('dashboard.title')}</option>
                                                    <option value="map">{__('sp.map')}</option>
                                                </Select>
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-3">
                                            <Field label={__('sp.target')} error={form.errors[`${base}.target`]}>
                                                {step.kind === 'text' ? (
                                                    <TextInput value="" placeholder={__('sp.not_needed_notes')} disabled />
                                                ) : (
                                                    <Select
                                                        value={step.target ?? ''}
                                                        onChange={(event) =>
                                                            editDefinition((draft) => {
                                                                draft.workflow[index].target = event.target.value;
                                                            })
                                                        }
                                                    >
                                                        <option value="">{t('common.choose_placeholder')}</option>
                                                        {targetOptions.map((name) => (
                                                            <option key={name} value={name}>
                                                                {name}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                )}
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-3">
                                            <Field label={t('forms.description')}>
                                                <TextInput
                                                    value={step.description ?? ''}
                                                    onChange={(event) =>
                                                        editDefinition((draft) => {
                                                            draft.workflow[index].description = event.target.value;
                                                        })
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="sm:col-span-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                onClick={() =>
                                                    editDefinition((draft) => {
                                                        draft.workflow.splice(index, 1);
                                                    })
                                                }
                                            >
                                                ×
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Panel>

                <div className="flex items-center gap-3">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {form.processing ? __('sp.saving') : isEdit ? __('sp.save') : __('sp.create')}
                    </PrimaryButton>
                    <GhostLink href="/solution-packs">{t('actions.cancel')}</GhostLink>
                </div>
            </form>
        </AppLayout>
    );
}
