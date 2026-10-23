import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import {
    DangerButton,
    DataTable,
    Empty,
    Field,
    GhostLink,
    PageHeader,
    Panel,
    PrimaryButton,
    RowActions,
    Select,
    Table,
    TextInput,
    tdClass,
    tdMono,
    thClass,
} from '@/components/gis';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type DomainEntry = string | number | { value?: string | number; label?: string };

type FieldRecord = {
    id: number;
    name: string;
    alias?: string | null;
    type?: string | null;
    domain_values?: DomainEntry[] | null;
    required?: boolean;
    calculated_expression?: string | null;
    sort_order?: number | null;
};

type LayerRecord = {
    id: number;
    name: string;
    table_name?: string | null;
    geometry_type?: string | null;
};

const typeLabels: Record<string, string> = {
    string: 'Text',
    integer: 'Whole number',
    float: 'Decimal',
    number: 'Decimal',
    boolean: 'Yes / No',
    date: 'Date',
    datetime: 'Date & time',
};

const typeOptions: Array<[string, string]> = [
    ['string', 'Text'],
    ['integer', 'Whole number'],
    ['float', 'Decimal'],
    ['boolean', 'Yes / No'],
    ['date', 'Date'],
    ['datetime', 'Date & time'],
];

const emptyForm = {
    name: '',
    alias: '',
    type: 'string',
    required: false,
    domain: [] as string[],
    expression: '',
    sortOrder: 0,
};

function domainOf(field: FieldRecord): string[] {
    if (!Array.isArray(field.domain_values)) {
        return [];
    }
    return field.domain_values
        .map((entry) => (typeof entry === 'object' && entry !== null ? String(entry.value ?? '') : String(entry)))
        .filter((value) => value !== '');
}

export default function Fields({ layer, fields = [] }: { layer: LayerRecord; fields?: FieldRecord[] }) {
    const { abilities } = usePage().props as { abilities?: { edit?: boolean } };
    const canEdit = Boolean(abilities?.edit);

    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<FieldRecord | null>(null);
    const [form, setForm] = useState(emptyForm);
    const [domainInput, setDomainInput] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'X-Requested-With': 'XMLHttpRequest',
    };

    function openCreate() {
        setEditing(null);
        setForm({ ...emptyForm, domain: [], sortOrder: fields.length });
        setDomainInput('');
        setErrors({});
        setDialogOpen(true);
    }

    function openEdit(field: FieldRecord) {
        setEditing(field);
        setForm({
            name: field.name,
            alias: field.alias || '',
            type: field.type || 'string',
            required: Boolean(field.required),
            domain: domainOf(field),
            expression: field.calculated_expression || '',
            sortOrder: field.sort_order ?? 0,
        });
        setDomainInput('');
        setErrors({});
        setDialogOpen(true);
    }

    function addDomain() {
        const values = domainInput
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');
        if (values.length === 0) {
            return;
        }
        setForm((current) => ({
            ...current,
            domain: Array.from(new Set([...current.domain, ...values])),
        }));
        setDomainInput('');
    }

    function removeDomain(value: string) {
        setForm((current) => ({ ...current, domain: current.domain.filter((entry) => entry !== value) }));
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        setErrors({});

        const payload: Record<string, unknown> = {
            alias: form.alias || null,
            type: form.type,
            domain_values: form.domain.length > 0 ? form.domain : null,
            required: form.required,
            calculated_expression: form.expression || null,
            sort_order: Number(form.sortOrder) || 0,
        };
        if (!editing) {
            payload.name = form.name;
        }

        try {
            const url = editing ? `/api/layers/${layer.id}/fields/${editing.id}` : `/api/layers/${layer.id}/fields`;
            const response = await fetch(url, {
                method: editing ? 'PUT' : 'POST',
                headers,
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}) as Record<string, unknown>);

            if (!response.ok) {
                const next: Record<string, string[]> = {};
                Object.entries((data.errors as Record<string, unknown>) ?? {}).forEach(([key, value]) => {
                    next[key] = Array.isArray(value) ? (value as string[]) : [String(value)];
                });
                if (Object.keys(next).length === 0) {
                    next.name = [String(data.message || 'Could not save the field.')];
                }
                setErrors(next);
                return;
            }

            setDialogOpen(false);
            router.reload({ only: ['fields'] });
        } catch (error) {
            setErrors({ name: [error instanceof Error ? error.message : 'Could not save the field.'] });
        } finally {
            setSaving(false);
        }
    }

    async function remove(field: FieldRecord) {
        const confirmed = window.confirm(
            `Delete the "${field.alias || field.name}" field definition? The column and its data stay in the table.`,
        );
        if (!confirmed) {
            return;
        }

        const response = await fetch(`/api/layers/${layer.id}/fields/${field.id}`, {
            method: 'DELETE',
            headers,
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}) as Record<string, unknown>);
            window.alert(String(data.error || 'Could not delete the field.'));
            return;
        }
        router.reload({ only: ['fields'] });
    }

    const selectTypeOptions: Array<[string, string]> = typeOptions.some(([value]) => value === form.type)
        ? typeOptions
        : [...typeOptions, [form.type, typeLabels[form.type] || form.type]];

    return (
        <AppLayout title={`Fields — ${layer.name}`}>
            <PageHeader
                title="Fields"
                action={
                    <div className="flex flex-wrap gap-2">
                        <GhostLink href={`/layers/${layer.id}`}>Back to layer</GhostLink>
                        {canEdit ? <PrimaryButton onClick={openCreate}>Add field</PrimaryButton> : null}
                    </div>
                }
            />
            <Panel>
                <p className="max-w-prose text-sm text-muted-foreground">
                    Fields describe the columns of <span className="font-medium text-foreground">{layer.name}</span>
                    {layer.table_name ? (
                        <>
                            {' '}
                            (<span className="font-mono">{layer.table_name}</span>)
                        </>
                    ) : null}
                    . Labels, allowed values and formulas apply everywhere the layer is edited; required and type rules
                    are enforced on every write.
                </p>

                <div className="mt-4">
                    {fields.length === 0 ? (
                        <Empty>
                            No fields defined yet. Add a field to label a column, restrict its allowed values, or make
                            it required.
                        </Empty>
                    ) : (
                        <DataTable>
                            <Table.Header>
                                <Table.Row>
                                    <Table.Head className={thClass}>Name</Table.Head>
                                    <Table.Head className={thClass}>Label</Table.Head>
                                    <Table.Head className={thClass}>Type</Table.Head>
                                    <Table.Head className={thClass}>Required</Table.Head>
                                    <Table.Head className={thClass}>Allowed values</Table.Head>
                                    <Table.Head className={thClass}>Formula</Table.Head>
                                    {canEdit ? <Table.Head className={thClass}>Actions</Table.Head> : null}
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {fields.map((field) => {
                                    const domain = domainOf(field);
                                    return (
                                        <Table.Row key={field.id}>
                                            <Table.Cell className={tdMono}>{field.name}</Table.Cell>
                                            <Table.Cell className={tdClass}>{field.alias || '—'}</Table.Cell>
                                            <Table.Cell className={tdClass}>
                                                {typeLabels[field.type || ''] || field.type || '—'}
                                            </Table.Cell>
                                            <Table.Cell className={tdClass}>{field.required ? 'Yes' : '—'}</Table.Cell>
                                            <Table.Cell className={`${tdClass} max-w-xs`}>
                                                <span className="block truncate">
                                                    {domain.length > 0 ? domain.join(', ') : '—'}
                                                </span>
                                            </Table.Cell>
                                            <Table.Cell className={tdMono}>
                                                {field.calculated_expression || '—'}
                                            </Table.Cell>
                                            {canEdit ? (
                                                <Table.Cell className={tdClass}>
                                                    <RowActions>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openEdit(field)}
                                                        >
                                                            Edit
                                                        </Button>
                                                        <DangerButton onClick={() => remove(field)}>
                                                            Delete
                                                        </DangerButton>
                                                    </RowActions>
                                                </Table.Cell>
                                            ) : null}
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </DataTable>
                    )}
                </div>
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${editing.name}` : 'Add field'}</DialogTitle>
                        <DialogDescription>
                            {editing
                                ? 'Update how this field is labelled, validated, and offered in editors.'
                                : 'Define a column of this layer. The column is created when it does not exist yet.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <Field
                            label="Name"
                            error={errors.name}
                            hint={editing ? undefined : 'Letters, numbers and underscores; cannot be changed later.'}
                        >
                            <TextInput
                                value={form.name}
                                disabled={Boolean(editing)}
                                placeholder="e.g. depth"
                                onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                            />
                        </Field>

                        <Field label="Label" error={errors.alias} hint="Shown in the attribute table and inspectors.">
                            <TextInput
                                value={form.alias}
                                placeholder="e.g. Depth (m)"
                                onChange={(event) => setForm((current) => ({ ...current, alias: event.target.value }))}
                            />
                        </Field>

                        <Field label="Type" error={errors.type}>
                            <Select
                                value={form.type}
                                onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}
                            >
                                {selectTypeOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field
                            label="Allowed values"
                            error={errors.domain_values}
                            hint="Optional. When set, editors offer these choices instead of free text."
                        >
                            <div className="flex gap-2">
                                <TextInput
                                    value={domainInput}
                                    placeholder="Add a value"
                                    onChange={(event) => setDomainInput(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            addDomain();
                                        }
                                    }}
                                />
                                <Button type="button" variant="outline" onClick={addDomain}>
                                    Add
                                </Button>
                            </div>
                            {form.domain.length > 0 ? (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {form.domain.map((value) => (
                                        <span
                                            key={value}
                                            className="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs"
                                        >
                                            {value}
                                            <button
                                                type="button"
                                                aria-label={`Remove ${value}`}
                                                className="text-muted-foreground hover:text-destructive"
                                                onClick={() => removeDomain(value)}
                                            >
                                                ×
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            ) : null}
                        </Field>

                        <Field
                            label="Formula"
                            error={errors.calculated_expression}
                            hint="Optional. Arithmetic over other fields, e.g. depth * 2."
                        >
                            <TextInput
                                value={form.expression}
                                placeholder="depth * 2"
                                onChange={(event) =>
                                    setForm((current) => ({ ...current, expression: event.target.value }))
                                }
                            />
                        </Field>

                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.required}
                                onCheckedChange={(checked) =>
                                    setForm((current) => ({ ...current, required: checked === true }))
                                }
                            />
                            Required
                        </label>

                        <Field label="Sort order" error={errors.sort_order}>
                            <TextInput
                                type="number"
                                className="w-24"
                                value={form.sortOrder}
                                onChange={(event) =>
                                    setForm((current) => ({ ...current, sortOrder: Number(event.target.value) }))
                                }
                            />
                        </Field>

                        <div className="flex justify-end gap-2 border-t pt-3">
                            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                Cancel
                            </Button>
                            <PrimaryButton type="submit" disabled={saving}>
                                {saving ? 'Saving…' : editing ? 'Save field' : 'Add field'}
                            </PrimaryButton>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
