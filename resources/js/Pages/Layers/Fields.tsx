import { router, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
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
    string: 'sp.opt.text',
    integer: 'sp.opt.integer',
    float: 'sp.opt.decimal',
    number: 'sp.opt.decimal',
    boolean: 'sp.opt.boolean',
    date: 'sp.opt.date',
    datetime: 'sp.opt.datetime',
};

const typeOptions: Array<[string, string]> = [
    ['string', 'sp.opt.text'],
    ['integer', 'sp.opt.integer'],
    ['float', 'sp.opt.decimal'],
    ['boolean', 'sp.opt.boolean'],
    ['date', 'sp.opt.date'],
    ['datetime', 'sp.opt.datetime'],
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

export default function Fields({ layer, fields = [], readOnly = false }: { layer: LayerRecord; fields?: FieldRecord[]; readOnly?: boolean }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props as { abilities?: { edit?: boolean } };
    // SQL-view layers are read-only: hide add/edit/delete (the server refuses them too).
    const canEdit = Boolean(abilities?.edit) && !readOnly;

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
                    next.name = [String(data.message || __('fields.save_failed'))];
                }
                setErrors(next);
                return;
            }

            setDialogOpen(false);
            router.reload({ only: ['fields'] });
        } catch (error) {
            setErrors({ name: [error instanceof Error ? error.message : __('fields.save_failed')] });
        } finally {
            setSaving(false);
        }
    }

    async function remove(field: FieldRecord) {
        const confirmed = window.confirm(
            __('fields.confirm_delete', { name: field.alias || field.name }),
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
            window.alert(String(data.error || __('fields.delete_failed')));
            return;
        }
        router.reload({ only: ['fields'] });
    }

    const selectTypeOptions: Array<[string, string]> = typeOptions.some(([value]) => value === form.type)
        ? typeOptions
        : [...typeOptions, [form.type, typeLabels[form.type] || form.type]];

    return (
        <AppLayout title={__('fields.title', { name: layer.name })}>
            <PageHeader
                title={t('layers.fields')}
                action={
                    <div className="flex flex-wrap gap-2">
                        <GhostLink href={`/layers/${layer.id}`}>{t('layers.back_layer')}</GhostLink>
                        {canEdit ? <PrimaryButton onClick={openCreate}>{t('layers.add_field')}</PrimaryButton> : null}
                        {readOnly ? <span className="text-sm text-muted-foreground">{t('layers.read_only_notice')}</span> : null}
                    </div>
                }
            />
            <Panel>
                <p className="max-w-prose text-sm text-muted-foreground">
                    {__('fields.intro')} <span className="font-medium text-foreground">{layer.name}</span>
                    {layer.table_name ? (
                        <>
                            {' '}
                            (<span dir="ltr" className="font-mono">{layer.table_name}</span>)
                        </>
                    ) : null}
                    {__('fields.intro_rules')}
                </p>

                <div className="mt-4">
                    {fields.length === 0 ? (
                        <Empty>
                            {__('fields.empty')}
                        </Empty>
                    ) : (
                        <DataTable>
                            <Table.Header>
                                <Table.Row>
                                    <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                    <Table.Head className={thClass}>{t('forms.label')}</Table.Head>
                                    <Table.Head className={thClass}>{t('forms.type')}</Table.Head>
                                    <Table.Head className={thClass}>{__('fields.required')}</Table.Head>
                                    <Table.Head className={thClass}>{t('solution_packs.allowed_values')}</Table.Head>
                                    <Table.Head className={thClass}>{t('common.formula')}</Table.Head>
                                    {canEdit ? <Table.Head className={thClass}>{t('forms.actions')}</Table.Head> : null}
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {fields.map((field) => {
                                    const domain = domainOf(field);
                                    return (
                                        <Table.Row key={field.id}>
                                            <Table.Cell className={tdMono}>{field.name}</Table.Cell>
                                            <Table.Cell className={tdClass}>{field.alias || t('common.em_dash')}</Table.Cell>
                                            <Table.Cell className={tdClass}>
                                                {typeLabels[field.type || ''] ? __(typeLabels[field.type || '']) : field.type || t('common.em_dash')}
                                            </Table.Cell>
                                            <Table.Cell className={tdClass}>{field.required ? __('fields.yes') : t('common.em_dash')}</Table.Cell>
                                            <Table.Cell className={`${tdClass} max-w-xs`}>
                                                <span className="block truncate">
                                                    {domain.length > 0 ? domain.join(', ') : t('common.em_dash')}
                                                </span>
                                            </Table.Cell>
                                            <Table.Cell className={tdMono}>
                                                {field.calculated_expression || t('common.em_dash')}
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
                                                            {__('fields.edit')}
                                                        </Button>
                                                        <DangerButton onClick={() => remove(field)}>
                                                            {__('fields.delete')}
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
                        <DialogTitle>{editing ? __('fields.edit_name', { name: editing.name }) : t('layers.add_field')}</DialogTitle>
                        <DialogDescription>
                            {editing
                                ? __('fields.edit_help')
                                : __('fields.new_help')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <Field
                            label={t('forms.name')}
                            error={errors.name}
                            hint={editing ? undefined : __('fields.name_hint')}
                        >
                            <TextInput
                                value={form.name}
                                disabled={Boolean(editing)}
                                placeholder={__('fields.ph_name')}
                                onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                            />
                        </Field>

                        <Field label={t('forms.label')} error={errors.alias} hint={__('fields.hint_alias')}>
                            <TextInput
                                value={form.alias}
                                placeholder={__('fields.ph_alias')}
                                onChange={(event) => setForm((current) => ({ ...current, alias: event.target.value }))}
                            />
                        </Field>

                        <Field label={t('forms.type')} error={errors.type}>
                            <Select
                                value={form.type}
                                onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}
                            >
                                {selectTypeOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {__(label)}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field
                            label={t('solution_packs.allowed_values')}
                            error={errors.domain_values}
                            hint={__('fields.hint_domain')}
                        >
                            <div className="flex gap-2">
                                <TextInput
                                    value={domainInput}
                                    placeholder={__('fields.ph_value')}
                                    onChange={(event) => setDomainInput(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            addDomain();
                                        }
                                    }}
                                />
                                <Button type="button" variant="outline" onClick={addDomain}>
                                    {__('fields.add')}
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
                                                aria-label={__('fields.remove_value', { value })}
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
                            label={t('common.formula')}
                            error={errors.calculated_expression}
                            hint={__('fields.hint_formula')}
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
                            {__('fields.required')}
                        </label>

                        <Field label={__('fields.sort_order')} error={errors.sort_order}>
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
                                {__('fields.cancel')}
                            </Button>
                            <PrimaryButton type="submit" disabled={saving}>
                                {saving ? __('fields.saving') : editing ? __('fields.save') : t('layers.add_field')}
                            </PrimaryButton>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
