import { useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { DangerButton, Field, PageHeader, Panel, PrimaryButton, Table, TextInput, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ tokens = [], plainTextToken = null }) {
    const form = useForm({ name: '' });

    return (
        <AppLayout title="API tokens">
            <PageHeader title="API tokens" />
            {plainTextToken ? (
                <p className="mb-4 border border-amber bg-amber/10 font-mono text-amber">
                    Copy this token now. It will not be shown again. {plainTextToken}
                </p>
            ) : null}
            <form
                className="mb-6 flex max-w-xl items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/settings/api-tokens');
                }}
            >
                <Field label="Token name" error={form.errors.name}>
                    <TextInput value={form.data.name} required placeholder="CLI, mobile app" onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Create token
                </PrimaryButton>
            </form>
            <p className="mb-4 text-muted-foreground">Personal access tokens authenticate API requests with Authorization: Bearer.</p>
            <Panel>
                <DataTable>
                    <Table.Header>
                        <Table.Row>
                            <Table.Head className={thClass}>Name</Table.Head>
                            <Table.Head className={thClass}>Abilities</Table.Head>
                            <Table.Head className={thClass}>Last used</Table.Head>
                            <Table.Head className={`${thClass} text-right`}>Actions</Table.Head>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {tokens.map((token) => (
                            <Table.Row key={token.id}>
                                <Table.Cell className={tdClass}>{token.name}</Table.Cell>
                                <Table.Cell className={tdMono}>{(token.abilities || []).join(', ') || '—'}</Table.Cell>
                                <Table.Cell className={tdMuted}>{when(token.last_used_at) === '—' ? 'Never' : when(token.last_used_at)}</Table.Cell>
                                <Table.Cell className={`${tdClass} text-right`}>
                                    <DangerButton onClick={() => destroyResource(`/settings/api-tokens/${token.id}`, 'Revoke this token?')}>Revoke</DangerButton>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </DataTable>
            </Panel>
        </AppLayout>
    );
}
