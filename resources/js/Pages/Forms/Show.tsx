import { useState } from 'react';
import { whenTime } from '@/lib/format';
import { useTranslation, __ } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { count } from '@/lib/format';
import { Button } from '@/components/ui/button';
import { ActionLink, DataTable, Empty, Field, GhostLink, Heading, PageHeader, Pager, Panel, Pill, PrimaryButton, Select, Table, TextInput, tdClass, tdMuted, thClass } from '@/components/gis';

function submittedAt(value, t) {
    if (!value) {
        return t('common.em_dash');
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return whenTime(date);
}

function attributeValue(submission, name, t) {
    const value = submission.attributes?.[name];
    if (value === null || value === undefined || value === '') {
        return t('common.em_dash');
    }

    if (typeof value === 'boolean') {
        return value ? __('forms.show.yes') : __('forms.show.no');
    }

    return String(value);
}

function locationValue(submission, t) {
    if (submission.latitude !== null && submission.latitude !== undefined && submission.longitude !== null && submission.longitude !== undefined) {
        return `${submission.latitude}, ${submission.longitude}`;
    }

    return submission.geometry_wkt || t('common.em_dash');
}

export default function Show({ form, submissions, submissionCount = 0, summary = null, filters = {}, requiresGeometry = false, links = {}, maps = [] }) {
    const { t } = useTranslation();
    const { abilities, errors = {} } = usePage().props;
    const rows = submissions?.data || [];
    const fields = (form.schema || []).filter((field) => field?.name);
    const [draft, setDraft] = useState({
        from: filters.from || '',
        to: filters.to || '',
        field: filters.field || '',
        value: filters.value || '',
    });
    const matching = summary?.matching ?? submissionCount;
    const total = summary?.total ?? submissionCount;
    const filtersActive = Boolean(filters.from || filters.to || filters.field || filters.value);

    function visit(next) {
        const params = {};
        if (next.from) params.from = next.from;
        if (next.to) params.to = next.to;
        if (next.field) params.field = next.field;
        if (next.field && next.value) params.value = next.value;
        router.get(`/forms/${form.id}`, params, { preserveScroll: true });
    }

    function applyFilters(event) {
        event.preventDefault();
        visit(draft);
    }

    function clearFilters() {
        const empty = { from: '', to: '', field: '', value: '' };
        setDraft(empty);
        visit(empty);
    }

    function filterByValue(fieldName, value) {
        const next = { ...draft, field: fieldName, value: value || '' };
        setDraft(next);
        visit(next);
    }

    return (
        <AppLayout title={form.name}>
            <PageHeader
                title={form.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        {abilities?.edit ? <GhostLink href={`/forms/${form.id}/edit`}>{t('actions.edit')}</GhostLink> : null}
                        {form.is_public && form.share_token ? <GhostLink href={`/f/${form.share_token}`}>{__('forms.show.public_link')}</GhostLink> : null}
                        <GhostLink href="/forms">{t('actions.back')}</GhostLink>
                    </div>
                }
            />

            <div className="mt-4 grid gap-4">
                <Panel>
                    <div className="flex flex-wrap items-center gap-2">
                        <Pill live={form.is_public}>{form.is_public ? t('common.public') : t('common.private')}</Pill>
                        <Pill>{form.layer?.name || __('forms.show.standalone')}</Pill>
                        <Pill>{requiresGeometry ? __('forms.show.location_required') : __('forms.show.location_optional')}</Pill>
                    </div>
                    <p className="mt-3 text-sm text-muted-foreground">{form.description || __('forms.show.no_description')}</p>
                    {form.is_public && form.share_token ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {__('forms.show.share_path')} <span dir="ltr" className="font-mono">/f/{form.share_token}</span>
                        </p>
                    ) : null}
                    <p className="mt-3 text-sm">
                        <span className="font-medium">{count(matching)}</span>
                        {matching === total ? ` ${__('forms.show.submissions_word')}` : ` ${__('forms.show.of_total', { total: count(total) })}`}
                    </p>
                    {summary ? (
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Pill>{count(summary.with_location)} with location</Pill>
                            <Pill>{count(summary.with_attachment)} with attachment</Pill>
                        </div>
                    ) : null}
                    {summary?.field?.counts?.length ? (
                        <div className="mt-3">
                            <p className="text-xs text-muted-foreground">{summary.field.label}</p>
                            <div className="mt-1 flex flex-wrap gap-2">
                                {summary.field.counts.map((entry) => (
                                    <Button
                                        key={`${summary.field.name}-${entry.value}-${entry.count}`}
                                        type="button"
                                        size="sm"
                                        variant={filters.field === summary.field.name && filters.value === entry.value ? 'default' : 'outline'}
                                        onClick={() => filterByValue(summary.field.name, entry.value)}
                                    >
                                        {entry.value || __('forms.show.blank')} · {count(entry.count)}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a href={links.export_csv}>{__('forms.show.export_csv')}</a>
                        </Button>
                        <Button asChild variant="outline">
                            <a href={links.export_excel}>{__('forms.show.export_xlsx')}</a>
                        </Button>
                        {links.attributes ? <GhostLink href={links.attributes}>{t('layers.attribute_table')}</GhostLink> : null}
                        {links.map ? <GhostLink href={links.map}>{__('forms.show.open_map')}</GhostLink> : null}
                        {links.layer_csv ? (
                            <Button asChild variant="outline">
                                <a href={links.layer_csv}>{__('forms.show.export_layer_csv')}</a>
                            </Button>
                        ) : null}
                        {links.layer_excel ? (
                            <Button asChild variant="outline">
                                <a href={links.layer_excel}>{__('forms.show.export_layer_xlsx')}</a>
                            </Button>
                        ) : null}
                    </div>
                    {maps.length ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            {__('forms.show.saved_maps')}{' '}
                            {maps.map((map, index) => (
                                <span key={map.id}>
                                    {index > 0 ? ', ' : null}
                                    <ActionLink href={map.url}>{map.name}</ActionLink>
                                </span>
                            ))}
                        </p>
                    ) : null}
                </Panel>

                <Panel>
                    <Heading as="h2">{__('forms.show.submissions')}</Heading>
                    <form onSubmit={applyFilters} className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <Field label={t('common.from')} error={errors.from}>
                            <TextInput type="date" value={draft.from} onChange={(event) => setDraft({ ...draft, from: event.target.value })} />
                        </Field>
                        <Field label={__('forms.show.to')} error={errors.to}>
                            <TextInput type="date" value={draft.to} onChange={(event) => setDraft({ ...draft, to: event.target.value })} />
                        </Field>
                        <Field label={t('common.field')}>
                            <Select value={draft.field} onChange={(event) => setDraft({ ...draft, field: event.target.value, value: event.target.value ? draft.value : '' })}>
                                <option value="">{t('common.any_field')}</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {field.label || field.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={__('forms.show.value')}>
                            <TextInput
                                value={draft.value}
                                disabled={!draft.field}
                                placeholder={draft.field ? __('forms.show.equals') : __('forms.show.choose_field')}
                                onChange={(event) => setDraft({ ...draft, value: event.target.value })}
                            />
                        </Field>
                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit">{__('forms.show.filter')}</PrimaryButton>
                            {filtersActive ? (
                                <Button type="button" variant="outline" onClick={clearFilters}>
                                    {__('forms.show.clear')}
                                </Button>
                            ) : null}
                        </div>
                    </form>
                    {rows.length === 0 ? (
                        <div className="mt-4">
                            <Empty>{total === 0 ? __('forms.show.no_submissions') : __('forms.show.no_match')}</Empty>
                        </div>
                    ) : (
                        <div className="mt-4 grid gap-3">
                            <DataTable>
                                <Table.Header>
                                    <Table.Row>
                                        <Table.Head className={thClass}>{__('forms.show.submitted')}</Table.Head>
                                        {fields.map((field) => (
                                            <Table.Head key={field.name} className={thClass}>
                                                {field.label || field.name}
                                            </Table.Head>
                                        ))}
                                        <Table.Head className={thClass}>{__('forms.show.location')}</Table.Head>
                                        {form.layer ? <Table.Head className={thClass}>{__('forms.show.feature')}</Table.Head> : null}
                                        <Table.Head className={thClass}>{t('common.attachment')}</Table.Head>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.map((submission) => (
                                        <Table.Row key={submission.id}>
                                            <Table.Cell className={tdMuted}>{submittedAt(submission.created_at, t)}</Table.Cell>
                                            {fields.map((field) => (
                                                <Table.Cell key={field.name} className={tdClass}>
                                                    {attributeValue(submission, field.name, t)}
                                                </Table.Cell>
                                            ))}
                                            <Table.Cell className={tdClass}>{locationValue(submission, t)}</Table.Cell>
                                            {form.layer ? (
                                                <Table.Cell className={tdMonoOrMuted(submission.feature_id)}>
                                                    {submission.feature_id ? `#${submission.feature_id}` : t('common.em_dash')}
                                                </Table.Cell>
                                            ) : null}
                                            <Table.Cell className={tdMuted}>{submission.attachment_name || t('common.em_dash')}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </DataTable>
                            <Pager paginator={submissions} />
                        </div>
                    )}
                </Panel>

                <Panel>
                    <Heading as="h2">{t('layers.fields')}</Heading>
                    {fields.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">{__('forms.show.no_fields')}</p>
                    ) : (
                        <ul className="mt-3 max-w-xl divide-y divide-border border border-border">
                            {fields.map((field) => (
                                <li key={field.name} className="flex justify-between gap-3 px-3 py-2 text-sm">
                                    <span>{field.label || field.name}</span>
                                    <span className="text-end font-mono text-muted-foreground">
                                        {field.type}
                                        {field.required ? ' · required' : ''}
                                        {field.visibility?.field ? ` · ${visibilityLabel(field.visibility)}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}

function tdMonoOrMuted(featureId) {
    return featureId ? `${tdClass} font-mono` : tdMuted;
}

function visibilityLabel(rule) {
    const operator = rule.operator === 'not_equals' ? '≠' : '=';
    const value = rule.value ? rule.value : __('forms.show.blank_value');

    return __(rule.action === 'hide' ? 'forms.show.hide_rule' : 'forms.show.show_rule', { field: rule.field, op: operator, value });
}
