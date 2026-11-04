import { Link, useForm, router } from '@inertiajs/react';
import { whenTime } from '@/lib/format';
import { enumLabel } from '@/lib/enum-labels';
import { useState } from 'react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';

type EventRow = {
    id: number;
    event_type: string;
    event_label?: string;
    body?: string | null;
    actor_user_id?: number | null;
    created_at?: string | null;
};

type RoleRow = {
    id: number;
    role: string;
    user_id: number;
    user?: { id: number; name: string; email?: string } | null;
};

type TaskRow = {
    id: number;
    title: string;
    status: string;
    priority: string;
    assigned_avl_unit_id?: number | null;
    assigned_user_id?: number | null;
    due_at?: string | null;
    result?: string | null;
    avl_unit?: {
        id: number;
        unit_id: string;
        resource_class: string;
        resource_status: string;
        last_ping_at?: string | null;
    } | null;
    update_url: string;
    destroy_url: string;
};

type PlanRow = {
    id: number;
    version: number;
    status: string;
    objectives?: string | null;
    strategies?: string | null;
    safety_message?: string | null;
    operational_period_id?: number | null;
    approved_at?: string | null;
    submit_url: string;
    approve_url: string;
    update_url: string;
};

type PeriodRow = {
    id: number;
    starts_at: string;
    ends_at?: string | null;
    objectives?: string | null;
};

type SitRepRow = {
    id: number;
    summary?: string | null;
    prepared_at?: string | null;
    export_json_url: string;
    export_pdf_url: string;
};

type IncidentDetail = {
    id: number;
    external_id: string;
    title: string;
    status: string;
    priority: number;
    incident_type?: string | null;
    notes?: string | null;
    opened_at?: string | null;
    operational_periods: PeriodRow[];
    plans: PlanRow[];
    current_plan?: PlanRow | null;
    roles: RoleRow[];
    tasks: TaskRow[];
    event_log: EventRow[];
    sitreps: SitRepRow[];
};

type AvlUnitOption = {
    id: number;
    unit_id: string;
    resource_class: string;
    resource_status: string;
};

type OrgUser = { id: number; name: string; email?: string };

type SitRepPrefill = {
    summary: string;
    casualties: Record<string, unknown>;
    resources: Record<string, unknown>;
    lifelines_status: Record<string, unknown>;
    next_steps: string;
};

type WorkspaceMember = {
    id: number;
    organization_id?: number | null;
    organization?: { id: number; name: string } | null;
    role: string;
    layer_ids: number[];
    expires_at?: string | null;
    revoked_at?: string | null;
    is_active: boolean;
    revoke_url: string;
};

type WorkspacePayload = {
    id?: number | null;
    status?: string;
    members?: WorkspaceMember[];
    invite_url?: string;
    inviteable_organizations?: { id: number; name: string }[];
    shareable_layers?: { id: number; name: string }[];
    role_options?: string[];
    shared?: boolean;
    role?: string;
    layer_ids?: number[];
    expires_at?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    incident: IncidentDetail & { shared?: boolean; shared_layer_ids?: number[] };
    avl_units: AvlUnitOption[];
    org_users: OrgUser[];
    sitrep_prefill: SitRepPrefill;
    role_options: string[];
    task_status_options: string[];
    task_priority_options: string[];
    index_url: string;
    urls: {
        close: string;
        operational_periods: string;
        plans: string;
        roles: string;
        tasks: string;
        sitreps: string;
    };
    can_dispatch: boolean;
    can_command: boolean;
    is_owner?: boolean;
    workspace?: WorkspacePayload | null;
};

type TabKey = 'timeline' | 'roles' | 'tasks' | 'iap' | 'sitrep' | 'workspace';

export default function IncidentShow({
    advisory,
    authority = null,
    incident,
    avl_units,
    org_users,
    sitrep_prefill,
    role_options,
    task_status_options,
    task_priority_options,
    index_url,
    urls,
    can_dispatch,
    can_command,
    is_owner = true,
    workspace = null,
}: Props) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<TabKey>(is_owner ? 'timeline' : 'tasks');
    const [selectedLayerIds, setSelectedLayerIds] = useState<number[]>([]);

    const periodForm = useForm({
        starts_at: new Date().toISOString().slice(0, 16),
        ends_at: '',
        objectives: '',
    });
    const planForm = useForm({
        operational_period_id: incident.operational_periods[0]?.id ?? '',
        objectives: '',
        strategies: '',
        safety_message: '',
    });
    const roleForm = useForm({ role: role_options[0] ?? 'incident_commander', user_id: org_users[0]?.id ?? '' });
    const taskForm = useForm({
        title: '',
        assigned_avl_unit_id: '',
        assigned_user_id: '',
        status: 'open',
        priority: 'normal',
        due_at: '',
    });
    const sitrepForm = useForm({
        summary: sitrep_prefill.summary,
        next_steps: sitrep_prefill.next_steps,
        casualties: JSON.stringify(sitrep_prefill.casualties ?? {}, null, 2),
        resources: JSON.stringify(sitrep_prefill.resources ?? {}, null, 2),
        lifelines_status: JSON.stringify(sitrep_prefill.lifelines_status ?? {}, null, 2),
    });
    const inviteForm = useForm({
        organization_id: workspace?.inviteable_organizations?.[0]?.id ?? '',
        role: workspace?.role_options?.[0] ?? 'collaborator',
        expires_at: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16),
        layer_ids: [] as number[],
    });

    const tabs: { key: TabKey; label: string }[] = is_owner
        ? [
              { key: 'timeline', label: t('disaster.ics_timeline') },
              { key: 'roles', label: t('disaster.ics_roles') },
              { key: 'tasks', label: t('disaster.ics_tasks') },
              { key: 'iap', label: t('disaster.ics_iap') },
              { key: 'sitrep', label: t('disaster.ics_sitrep') },
              { key: 'workspace', label: t('disaster.workspace') },
          ]
        : [
              { key: 'tasks', label: t('disaster.ics_tasks') },
              { key: 'workspace', label: t('disaster.workspace') },
          ];

    function submitPeriod(event: React.FormEvent) {
        event.preventDefault();
        periodForm.transform((data) => ({
            ...data,
            ends_at: data.ends_at || null,
            objectives: data.objectives || null,
        }));
        periodForm.post(urls.operational_periods, { preserveScroll: true });
    }

    function submitPlan(event: React.FormEvent) {
        event.preventDefault();
        planForm.transform((data) => ({
            ...data,
            operational_period_id: data.operational_period_id === '' ? null : Number(data.operational_period_id),
        }));
        planForm.post(urls.plans, { preserveScroll: true, onSuccess: () => planForm.reset('objectives', 'strategies', 'safety_message') });
    }

    function submitRole(event: React.FormEvent) {
        event.preventDefault();
        roleForm.transform((data) => ({ ...data, user_id: Number(data.user_id) }));
        roleForm.post(urls.roles, { preserveScroll: true });
    }

    function submitTask(event: React.FormEvent) {
        event.preventDefault();
        taskForm.transform((data) => ({
            ...data,
            assigned_avl_unit_id: data.assigned_avl_unit_id === '' ? null : Number(data.assigned_avl_unit_id),
            assigned_user_id: data.assigned_user_id === '' ? null : Number(data.assigned_user_id),
            due_at: data.due_at || null,
        }));
        taskForm.post(urls.tasks, {
            preserveScroll: true,
            onSuccess: () => taskForm.reset('title', 'assigned_avl_unit_id', 'assigned_user_id', 'due_at'),
        });
    }

    function submitSitRep(event: React.FormEvent) {
        event.preventDefault();
        sitrepForm.transform((data) => ({
            summary: data.summary,
            next_steps: data.next_steps,
            casualties: safeJson(data.casualties),
            resources: safeJson(data.resources),
            lifelines_status: safeJson(data.lifelines_status),
        }));
        sitrepForm.post(urls.sitreps, { preserveScroll: true });
    }

    function assignTaskUnit(task: TaskRow, unitId: string) {
        router.put(
            task.update_url,
            { assigned_avl_unit_id: unitId === '' ? null : Number(unitId) },
            { preserveScroll: true },
        );
    }

    function submitInvite(event: React.FormEvent) {
        event.preventDefault();
        if (!workspace?.invite_url) {
            return;
        }
        inviteForm.transform((data) => ({
            ...data,
            organization_id: Number(data.organization_id),
            layer_ids: selectedLayerIds,
            expires_at: data.expires_at,
        }));
        inviteForm.post(workspace.invite_url, {
            preserveScroll: true,
            onSuccess: () => setSelectedLayerIds([]),
        });
    }

    function toggleLayer(layerId: number) {
        setSelectedLayerIds((prev) =>
            prev.includes(layerId) ? prev.filter((id) => id !== layerId) : [...prev, layerId],
        );
    }

    const currentPlan = incident.current_plan;
    const members = workspace?.members ?? [];

    return (
        <AppLayout
            title={incident.title}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.incidents_short'), href: index_url },
                { title: incident.title },
            ]}
        >
            <PageHeader
                title={incident.title}
                action={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">{enumLabel(incident.status)}</Badge>
                        {incident.priority ? (
                            <Badge variant="outline">{t(`disaster.priority.${incident.priority}`)}</Badge>
                        ) : null}
                        {incident.incident_type ? <Badge variant="outline">{enumLabel(incident.incident_type)}</Badge> : null}
                        {!is_owner ? <Badge variant="outline">{t('disaster.shared_badge')}</Badge> : null}
                        {is_owner && can_dispatch && incident.status !== 'closed' ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.post(urls.close, {}, { preserveScroll: true })}
                            >
                                {t('disaster.close_incident')}
                            </Button>
                        ) : null}
                        <Link href={index_url} className="text-sm font-medium text-primary hover:underline">
                            {t('disaster.incidents_short')}
                        </Link>
                    </div>
                }
            />
            {advisory ? (
                <AdvisoryBanner
                    advisory={advisory}
                    authority={authority}
                    className="mb-6 max-w-2xl text-sm text-muted-foreground"
                />
            ) : null}

            <div className="mb-4 flex flex-wrap gap-2 border-b border-border pb-2">
                {tabs.map((item) => (
                    <Button
                        key={item.key}
                        type="button"
                        size="sm"
                        variant={tab === item.key ? 'default' : 'ghost'}
                        onClick={() => setTab(item.key)}
                    >
                        {item.label}
                    </Button>
                ))}
            </div>

            {tab === 'timeline' ? (
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.ics_timeline')}</h2>
                    </div>
                    {incident.event_log.length === 0 ? (
                        <Empty>{t('disaster.empty.event_log')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {incident.event_log.map((event) => (
                                <li key={event.id} className="space-y-1 px-3 py-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="outline">{event.event_label || event.event_type}</Badge>
                                        <span className="text-xs text-muted-foreground">{event.created_at ? whenTime(event.created_at) : ''}</span>
                                    </div>
                                    <p>{event.body}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            ) : null}

            {tab === 'roles' ? (
                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.ics_roles')}</h2>
                        </div>
                        {incident.roles.length === 0 ? (
                            <Empty>{t('disaster.empty.roles')}</Empty>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {incident.roles.map((role) => (
                                    <li key={role.id} className="flex items-center gap-2 px-3 py-2">
                                        <span className="font-medium">{enumLabel(role.role)}</span>
                                        <span className="text-muted-foreground">{role.user?.name ?? `#${role.user_id}`}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                    {can_command ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.assign_role')}</h2>
                            </div>
                            <form onSubmit={submitRole} className="space-y-3 p-3">
                                <Field label={t('disaster.ics_role')}>
                                    <Select value={roleForm.data.role} onChange={(e) => roleForm.setData('role', e.target.value)}>
                                        {role_options.map((role) => (
                                            <option key={role} value={role}>
                                                {role}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('disaster.assignee')} error={roleForm.errors.user_id}>
                                    <Select
                                        value={String(roleForm.data.user_id)}
                                        onChange={(e) => roleForm.setData('user_id', e.target.value)}
                                    >
                                        {org_users.map((user) => (
                                            <option key={user.id} value={user.id}>
                                                {user.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <PrimaryButton type="submit" disabled={roleForm.processing}>
                                    {t('disaster.assign_role')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}
                </div>
            ) : null}

            {tab === 'tasks' ? (
                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.ics_tasks')}</h2>
                        </div>
                        {incident.tasks.length === 0 ? (
                            <Empty>{t('disaster.empty.tasks')}</Empty>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {incident.tasks.map((task) => (
                                    <li key={task.id} className="space-y-2 px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{task.title}</span>
                                            <Badge variant="secondary">{enumLabel(task.status)}</Badge>
                                            <Badge variant="outline">{enumLabel(task.priority)}</Badge>
                                        </div>
                                        {task.avl_unit ? (
                                            <p className="text-xs text-muted-foreground">
                                                {task.avl_unit.unit_id} · {enumLabel(task.avl_unit.resource_status)}
                                            </p>
                                        ) : null}
                                        {is_owner && can_dispatch ? (
                                            <Select
                                                value={String(task.assigned_avl_unit_id ?? '')}
                                                onChange={(e) => assignTaskUnit(task, e.target.value)}
                                            >
                                                <option value="">{t('disaster.unassigned')}</option>
                                                {avl_units.map((unit) => (
                                                    <option key={unit.id} value={unit.id}>
                                                        {unit.unit_id} ({enumLabel(unit.resource_status)})
                                                    </option>
                                                ))}
                                            </Select>
                                        ) : null}
                                        {!is_owner && workspace?.role === 'collaborator' ? (
                                            <Select
                                                value={task.status}
                                                onChange={(e) =>
                                                    router.put(
                                                        task.update_url,
                                                        { status: e.target.value },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {task_status_options.map((status) => (
                                                    <option key={status} value={status}>
                                                        {enumLabel(status)}
                                                    </option>
                                                ))}
                                            </Select>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                    {is_owner && can_dispatch ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.create_task')}</h2>
                            </div>
                            <form onSubmit={submitTask} className="space-y-3 p-3">
                                <Field label={t('forms.title')} error={taskForm.errors.title}>
                                    <TextInput
                                        value={taskForm.data.title}
                                        required
                                        onChange={(e) => taskForm.setData('title', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.avl_unit')}>
                                    <Select
                                        value={String(taskForm.data.assigned_avl_unit_id)}
                                        onChange={(e) => taskForm.setData('assigned_avl_unit_id', e.target.value)}
                                    >
                                        <option value="">{t('disaster.unassigned')}</option>
                                        {avl_units.map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.unit_id} ({enumLabel(unit.resource_status)})
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('forms.status')}>
                                    <Select value={taskForm.data.status} onChange={(e) => taskForm.setData('status', e.target.value)}>
                                        {task_status_options.map((status) => (
                                            <option key={status} value={status}>
                                                {enumLabel(status)}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('disaster.task_priority')}>
                                    <Select
                                        value={taskForm.data.priority}
                                        onChange={(e) => taskForm.setData('priority', e.target.value)}
                                    >
                                        {task_priority_options.map((priority) => (
                                            <option key={priority} value={priority}>
                                                {enumLabel(priority)}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <PrimaryButton type="submit" disabled={taskForm.processing}>
                                    {t('disaster.create_task')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}
                </div>
            ) : null}

            {tab === 'iap' ? (
                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.ics_iap')}</h2>
                        </div>
                        <div className="space-y-3 p-3 text-sm">
                            <div>
                                <h3 className="mb-1 font-medium">{t('disaster.operational_periods')}</h3>
                                {incident.operational_periods.length === 0 ? (
                                    <p className="text-muted-foreground">{t('disaster.empty.op_periods')}</p>
                                ) : (
                                    <ul className="space-y-1">
                                        {incident.operational_periods.map((period) => (
                                            <li key={period.id}>
                                                #{period.id} · {whenTime(period.starts_at)}
                                                {period.ends_at ? ` → ${whenTime(period.ends_at)}` : ''}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            {currentPlan ? (
                                <div className="space-y-2 rounded-md border border-border p-3">
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="secondary">v{currentPlan.version}</Badge>
                                        <Badge variant="outline">{enumLabel(currentPlan.status)}</Badge>
                                    </div>
                                    <p>
                                        <span className="font-medium">{t('disaster.objectives')}: </span>
                                        {currentPlan.objectives || t('common.em_dash')}
                                    </p>
                                    <p>
                                        <span className="font-medium">{t('disaster.strategies')}: </span>
                                        {currentPlan.strategies || t('common.em_dash')}
                                    </p>
                                    <p>
                                        <span className="font-medium">{t('disaster.safety_message')}: </span>
                                        {currentPlan.safety_message || t('common.em_dash')}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {can_dispatch && currentPlan.status === 'draft' ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.post(currentPlan.submit_url, {}, { preserveScroll: true })}
                                            >
                                                {t('disaster.submit_iap')}
                                            </Button>
                                        ) : null}
                                        {can_command &&
                                        (currentPlan.status === 'draft' || currentPlan.status === 'pending_approval') ? (
                                            <Button
                                                size="sm"
                                                onClick={() => router.post(currentPlan.approve_url, {}, { preserveScroll: true })}
                                            >
                                                {t('disaster.approve_iap')}
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            ) : (
                                <p className="text-muted-foreground">{t('disaster.empty.iap')}</p>
                            )}
                        </div>
                    </Panel>
                    {can_dispatch ? (
                        <div className="space-y-6">
                            <Panel>
                                <div className="border-b border-border px-3 py-2">
                                    <h2 className="text-sm font-semibold">{t('disaster.create_op_period')}</h2>
                                </div>
                                <form onSubmit={submitPeriod} className="space-y-3 p-3">
                                    <Field label={t('disaster.starts_at')} error={periodForm.errors.starts_at}>
                                        <TextInput
                                            type="datetime-local"
                                            value={periodForm.data.starts_at}
                                            required
                                            onChange={(e) => periodForm.setData('starts_at', e.target.value)}
                                        />
                                    </Field>
                                    <Field label={t('disaster.ends_at')}>
                                        <TextInput
                                            type="datetime-local"
                                            value={periodForm.data.ends_at}
                                            onChange={(e) => periodForm.setData('ends_at', e.target.value)}
                                        />
                                    </Field>
                                    <Field label={t('disaster.objectives')}>
                                        <TextInput
                                            value={periodForm.data.objectives}
                                            onChange={(e) => periodForm.setData('objectives', e.target.value)}
                                        />
                                    </Field>
                                    <PrimaryButton type="submit" disabled={periodForm.processing}>
                                        {t('disaster.create_op_period')}
                                    </PrimaryButton>
                                </form>
                            </Panel>
                            <Panel>
                                <div className="border-b border-border px-3 py-2">
                                    <h2 className="text-sm font-semibold">{t('disaster.draft_iap')}</h2>
                                </div>
                                <form onSubmit={submitPlan} className="space-y-3 p-3">
                                    <Field label={t('disaster.operational_period')}>
                                        <Select
                                            value={String(planForm.data.operational_period_id)}
                                            onChange={(e) => planForm.setData('operational_period_id', e.target.value)}
                                        >
                                            <option value="">{t('common.em_dash')}</option>
                                            {incident.operational_periods.map((period) => (
                                                <option key={period.id} value={period.id}>
                                                    #{period.id}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label={t('disaster.objectives')}>
                                        <TextInput
                                            value={planForm.data.objectives}
                                            onChange={(e) => planForm.setData('objectives', e.target.value)}
                                        />
                                    </Field>
                                    <Field label={t('disaster.strategies')}>
                                        <TextInput
                                            value={planForm.data.strategies}
                                            onChange={(e) => planForm.setData('strategies', e.target.value)}
                                        />
                                    </Field>
                                    <Field label={t('disaster.safety_message')}>
                                        <TextInput
                                            value={planForm.data.safety_message}
                                            onChange={(e) => planForm.setData('safety_message', e.target.value)}
                                        />
                                    </Field>
                                    <PrimaryButton type="submit" disabled={planForm.processing}>
                                        {t('disaster.draft_iap')}
                                    </PrimaryButton>
                                </form>
                            </Panel>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {tab === 'sitrep' && is_owner ? (
                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.saved_sitreps')}</h2>
                        </div>
                        {incident.sitreps.length === 0 ? (
                            <Empty>{t('disaster.empty.sitreps')}</Empty>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {incident.sitreps.map((sitrep) => (
                                    <li key={sitrep.id} className="space-y-2 px-3 py-2">
                                        <p className="font-medium">#{sitrep.id}</p>
                                        <p className="text-muted-foreground">{whenTime(sitrep.prepared_at)}</p>
                                        <p className="line-clamp-3">{sitrep.summary}</p>
                                        <div className="flex flex-wrap gap-2">
                                            <Button asChild size="sm" variant="outline">
                                                <a href={sitrep.export_json_url}>{t('disaster.export_json')}</a>
                                            </Button>
                                            <Button asChild size="sm" variant="outline">
                                                <a href={sitrep.export_pdf_url}>{t('disaster.export_pdf')}</a>
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                    {can_dispatch ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.generate_sitrep')}</h2>
                            </div>
                            <form onSubmit={submitSitRep} className="space-y-3 p-3">
                                <Field label={t('disaster.summary')} error={sitrepForm.errors.summary}>
                                    <textarea
                                        className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        value={sitrepForm.data.summary}
                                        onChange={(e) => sitrepForm.setData('summary', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.next_steps')}>
                                    <textarea
                                        className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        value={sitrepForm.data.next_steps}
                                        onChange={(e) => sitrepForm.setData('next_steps', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.casualties_json')}>
                                    <textarea
                                        className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                                        value={sitrepForm.data.casualties}
                                        onChange={(e) => sitrepForm.setData('casualties', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.resources_json')}>
                                    <textarea
                                        className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                                        value={sitrepForm.data.resources}
                                        onChange={(e) => sitrepForm.setData('resources', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.lifelines_json')}>
                                    <textarea
                                        className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                                        value={sitrepForm.data.lifelines_status}
                                        onChange={(e) => sitrepForm.setData('lifelines_status', e.target.value)}
                                    />
                                </Field>
                                <PrimaryButton type="submit" disabled={sitrepForm.processing}>
                                    {t('disaster.save_sitrep')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}
                </div>
            ) : null}

            {tab === 'workspace' ? (
                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">
                                {is_owner ? t('disaster.workspace_members') : t('disaster.workspace_shared_layers')}
                            </h2>
                        </div>
                        {is_owner ? (
                            members.length === 0 ? (
                                <Empty>{t('disaster.empty.workspace_members')}</Empty>
                            ) : (
                                <ul className="divide-y divide-border text-sm">
                                    {members.map((member) => (
                                        <li key={member.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                            <span className="font-medium">
                                                {member.organization?.name ?? `Org #${member.organization_id}`}
                                            </span>
                                            <Badge variant="secondary">{enumLabel(member.role)}</Badge>
                                            {!member.is_active ? (
                                                <Badge variant="outline">{member.revoked_at ? enumLabel('revoked') : enumLabel('expired')}</Badge>
                                            ) : null}
                                            <span className="text-xs text-muted-foreground">
                                                {t('disaster.workspace_expires')}: {whenTime(member.expires_at)}
                                            </span>
                                            {member.layer_ids.length > 0 ? (
                                                <span className="text-xs text-muted-foreground">
                                                    layers: {member.layer_ids.join(', ')}
                                                </span>
                                            ) : null}
                                            {can_command && member.is_active ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="ms-auto"
                                                    onClick={() =>
                                                        router.post(member.revoke_url, {}, { preserveScroll: true })
                                                    }
                                                >
                                                    {t('disaster.workspace_revoke')}
                                                </Button>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            )
                        ) : (
                            <div className="space-y-2 p-3 text-sm">
                                <p>
                                    {t('disaster.workspace_role')}:{' '}
                                    <Badge variant="secondary">{workspace?.role ?? 'viewer'}</Badge>
                                </p>
                                <p className="text-muted-foreground">
                                    {t('disaster.workspace_expires')}: {whenTime(workspace?.expires_at)}
                                </p>
                                <p>
                                    {t('disaster.workspace_shared_layers')}:{' '}
                                    {(workspace?.layer_ids ?? incident.shared_layer_ids ?? []).join(', ') || '—'}
                                </p>
                            </div>
                        )}
                    </Panel>
                    {is_owner && can_command && workspace?.invite_url ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.workspace_invite')}</h2>
                            </div>
                            <form onSubmit={submitInvite} className="space-y-3 p-3">
                                <Field label={t('disaster.invite_organization')} error={inviteForm.errors.organization_id}>
                                    <Select
                                        value={String(inviteForm.data.organization_id)}
                                        onChange={(e) => inviteForm.setData('organization_id', e.target.value)}
                                    >
                                        {(workspace.inviteable_organizations ?? []).map((org) => (
                                            <option key={org.id} value={org.id}>
                                                {org.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('disaster.workspace_role')}>
                                    <Select
                                        value={inviteForm.data.role}
                                        onChange={(e) => inviteForm.setData('role', e.target.value)}
                                    >
                                        {(workspace.role_options ?? ['viewer', 'collaborator']).map((role) => (
                                            <option key={role} value={role}>
                                                {role}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('disaster.workspace_expires')} error={inviteForm.errors.expires_at}>
                                    <TextInput
                                        type="datetime-local"
                                        value={inviteForm.data.expires_at}
                                        required
                                        onChange={(e) => inviteForm.setData('expires_at', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.select_layers')} error={inviteForm.errors.layer_ids}>
                                    <div className="max-h-40 space-y-1 overflow-y-auto rounded-md border border-border p-2 text-sm">
                                        {(workspace.shareable_layers ?? []).length === 0 ? (
                                            <p className="text-muted-foreground">{t('common.em_dash')}</p>
                                        ) : (
                                            (workspace.shareable_layers ?? []).map((layer) => (
                                                <label key={layer.id} className="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedLayerIds.includes(layer.id)}
                                                        onChange={() => toggleLayer(layer.id)}
                                                    />
                                                    <span>
                                                        {layer.name} (#{layer.id})
                                                    </span>
                                                </label>
                                            ))
                                        )}
                                    </div>
                                </Field>
                                <PrimaryButton type="submit" disabled={inviteForm.processing}>
                                    {t('disaster.workspace_invite')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}
                </div>
            ) : null}
        </AppLayout>
    );
}

function safeJson(value: string): Record<string, unknown> {
    try {
        const parsed = JSON.parse(value);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}
