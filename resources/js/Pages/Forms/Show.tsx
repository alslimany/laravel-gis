import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { count } from '@/lib/format';
import { Button } from '@/components/ui/button';
import { ActionLink, DataTable, Empty, Field, GhostLink, Heading, PageHeader, Pager, Panel, Pill, PrimaryButton, Select, Table, TextInput, tdClass, tdMuted, thClass } from '@/components/gis';

function submittedAt(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function attributeValue(submission, name) {
    const value = submission.attributes?.[name];
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value);
}

function locationValue(submission) {
    if (submission.latitude !== null && submission.latitude !== undefined && submission.longitude !== null && submission.longitude !== undefined) {
        return `${submission.latitude}, ${submission.longitude}`;
    }

    return submission.geometry_wkt || '—';
}

export default function Show({ form, submissions, submissionCount = 0, summary = null, filters = {}, requiresGeometry = false, links = {}, maps = [] }) {
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
                        {abilities?.edit ? <GhostLink href={`/forms/${form.id}/edit`}>Edit</GhostLink> : null}
                        {form.is_public && form.share_token ? <GhostLink href={`/f/${form.share_token}`}>Public link</GhostLink> : null}
                        <GhostLink href="/forms">Back</GhostLink>
                    </div>
                }
            />

            <div className="mt-4 grid gap-4">
                <Panel>
                    <div className="flex flex-wrap items-center gap-2">
                        <Pill live={form.is_public}>{form.is_public ? 'Public' : 'Private'}</Pill>
                        <Pill>{form.layer?.name || 'Standalone'}</Pill>
                        <Pill>{requiresGeometry ? 'Location required' : 'Location optional'}</Pill>
                    </div>
                    <p className="mt-3 text-sm text-muted-foreground">{form.description || 'No description yet.'}</p>
                    {form.is_public && form.share_token ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Share path <span className="font-mono">/f/{form.share_token}</span>
                        </p>
                    ) : null}
                    <p className="mt-3 text-sm">
                        <span className="font-medium">{count(matching)}</span>
                        {matching === total ? '' : ` of ${count(total)}`} submissions
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
                                        {entry.value || 'Blank'} · {count(entry.count)}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a href={links.export_csv}>Export submissions CSV</a>
                        </Button>
                        <Button asChild variant="outline">
                            <a href={links.export_excel}>Export submissions Excel</a>
                        </Button>
                        {links.attributes ? <GhostLink href={links.attributes}>Attribute table</GhostLink> : null}
                        {links.map ? <GhostLink href={links.map}>Open on map</GhostLink> : null}
                        {links.layer_csv ? (
                            <Button asChild variant="outline">
                                <a href={links.layer_csv}>Export layer CSV</a>
                            </Button>
                        ) : null}
                        {links.layer_excel ? (
                            <Button asChild variant="outline">
                                <a href={links.layer_excel}>Export layer Excel</a>
                            </Button>
                        ) : null}
                    </div>
                    {maps.length ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            Saved maps:{' '}
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
                    <Heading as="h2">Submissions</Heading>
                    <form onSubmit={applyFilters} className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <Field label="From" error={errors.from}>
                            <TextInput type="date" value={draft.from} onChange={(event) => setDraft({ ...draft, from: event.target.value })} />
                        </Field>
                        <Field label="To" error={errors.to}>
                            <TextInput type="date" value={draft.to} onChange={(event) => setDraft({ ...draft, to: event.target.value })} />
                        </Field>
                        <Field label="Field">
                            <Select value={draft.field} onChange={(event) => setDraft({ ...draft, field: event.target.value, value: event.target.value ? draft.value : '' })}>
                                <option value="">Any field</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {field.label || field.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Value">
                            <TextInput
                                value={draft.value}
                                disabled={!draft.field}
                                placeholder={draft.field ? 'Equals' : 'Choose a field'}
                                onChange={(event) => setDraft({ ...draft, value: event.target.value })}
                            />
                        </Field>
                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit">Filter</PrimaryButton>
                            {filtersActive ? (
                                <Button type="button" variant="outline" onClick={clearFilters}>
                                    Clear
                                </Button>
                            ) : null}
                        </div>
                    </form>
                    {rows.length === 0 ? (
                        <div className="mt-4">
                            <Empty>{total === 0 ? 'No submissions yet.' : 'No submissions match these filters.'}</Empty>
                        </div>
                    ) : (
                        <div className="mt-4 grid gap-3">
                            <DataTable>
                                <Table.Header>
                                    <Table.Row>
                                        <Table.Head className={thClass}>Submitted</Table.Head>
                                        {fields.map((field) => (
                                            <Table.Head key={field.name} className={thClass}>
                                                {field.label || field.name}
                                            </Table.Head>
                                        ))}
                                        <Table.Head className={thClass}>Location</Table.Head>
                                        {form.layer ? <Table.Head className={thClass}>Feature</Table.Head> : null}
                                        <Table.Head className={thClass}>Attachment</Table.Head>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.map((submission) => (
                                        <Table.Row key={submission.id}>
                                            <Table.Cell className={tdMuted}>{submittedAt(submission.created_at)}</Table.Cell>
                                            {fields.map((field) => (
                                                <Table.Cell key={field.name} className={tdClass}>
                                                    {attributeValue(submission, field.name)}
                                                </Table.Cell>
                                            ))}
                                            <Table.Cell className={tdClass}>{locationValue(submission)}</Table.Cell>
                                            {form.layer ? (
                                                <Table.Cell className={tdMonoOrMuted(submission.feature_id)}>
                                                    {submission.feature_id ? `#${submission.feature_id}` : '—'}
                                                </Table.Cell>
                                            ) : null}
                                            <Table.Cell className={tdMuted}>{submission.attachment_name || '—'}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </DataTable>
                            <Pager paginator={submissions} />
                        </div>
                    )}
                </Panel>

                <Panel>
                    <Heading as="h2">Fields</Heading>
                    {fields.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">This form has no fields yet.</p>
                    ) : (
                        <ul className="mt-3 max-w-xl divide-y divide-border border border-border">
                            {fields.map((field) => (
                                <li key={field.name} className="flex justify-between gap-3 px-3 py-2 text-sm">
                                    <span>{field.label || field.name}</span>
                                    <span className="text-right font-mono text-muted-foreground">
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
    const action = rule.action === 'hide' ? 'hide' : 'show';
    const operator = rule.operator === 'not_equals' ? '≠' : '=';
    const value = rule.value ? rule.value : 'blank';

    return `${action} when ${rule.field} ${operator} ${value}`;
}
