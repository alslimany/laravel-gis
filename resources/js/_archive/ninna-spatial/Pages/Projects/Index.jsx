import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { when } from '../../lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '../../Components/ui';

export default function Index({ projects }) {
    const { abilities } = usePage().props;
    const rows = projects?.data || [];

    return (
        <AppLayout title="Projects">
            <PageHeader
                title="Projects"
                action={
                    abilities?.edit ? (
                        <PrimaryLink href="/projects/create">
                            New project
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>No projects yet.</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>Name</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Layers</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Owner</Table.Head>
                                <Table.Head className={thClass}>Status</Table.Head>
                                <Table.Head className={thClass}>Actions</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((project) => (
                                <Table.Row key={project.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/projects/${project.id}`} className="font-medium text-primary hover:underline">
                                            {project.name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden md:table-cell`}>{project.layers?.length ?? project.layers_count ?? 0}</Table.Cell>
                                    <Table.Cell className={`${tdClass} hidden md:table-cell`}>{project.user?.name || '—'}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={project.is_public}>{project.is_public ? 'Public' : 'Private'}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/projects/${project.id}`} hideOnPhone>
                                                View
                                            </ActionLink>
                                            {abilities?.edit ? <ActionLink href={`/projects/${project.id}/edit`}>Edit</ActionLink> : null}
                                            {abilities?.edit ? (
                                                <ActionLink href={`/projects/${project.id}/share`} hideOnPhone>
                                                    Share
                                                </ActionLink>
                                            ) : null}
                                            {abilities?.edit ? (
                                                <ActionLink href={`/projects/${project.id}/invite`} hideOnPhone>
                                                    Invite
                                                </ActionLink>
                                            ) : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/projects/${project.id}`, 'Delete this project?')}>Delete</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={projects} />
            </Panel>
        </AppLayout>
    );
}
