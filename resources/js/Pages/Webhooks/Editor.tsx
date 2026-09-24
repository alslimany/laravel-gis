import { useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, PrimaryButton, TextInput } from '@/components/gis';

export default function Editor({ webhook = null, eventOptions = [] }) {
    const editing = Boolean(webhook);
    const form = useForm({
        name: webhook?.name || '',
        url: webhook?.url || '',
        events: webhook?.events || ['*'],
        secret: '',
        is_active: webhook ? Boolean(webhook.is_active) : true,
    });

    function toggleEvent(eventName) {
        const events = form.data.events.includes(eventName)
            ? form.data.events.filter((item) => item !== eventName)
            : [...form.data.events, eventName];
        form.setData('events', events);
    }

    function submit(event) {
        event.preventDefault();
        if (editing) form.put(`/webhooks/${webhook.id}`);
        else form.post('/webhooks');
    }

    return (
        <AppLayout title={editing ? `Edit ${webhook.name}` : 'New webhook'}>
            <PageHeader title={editing ? `Edit ${webhook.name}` : 'New webhook'} />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Endpoint URL" error={form.errors.url}>
                    <TextInput type="url" value={form.data.url} required placeholder="https://example.com/hooks" onChange={(event) => form.setData('url', event.target.value)} />
                </Field>
                <fieldset>
                    <legend className="mb-2 font-medium text-foreground">Events</legend>
                    <div className="space-y-2">
                        {eventOptions.map((option) => (
                            <label key={option} className="flex items-center gap-2">
                                <input type="checkbox" checked={form.data.events.includes(option)} onChange={() => toggleEvent(option)} />
                                <span className="font-mono">{option}</span>
                            </label>
                        ))}
                    </div>
                </fieldset>
                <Field label="Signing secret" hint={editing ? 'Leave blank to keep the current secret.' : 'Leave blank to generate one.'}>
                    <TextInput value={form.data.secret} onChange={(event) => form.setData('secret', event.target.value)} />
                </Field>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                    Active
                </label>
                <div className="flex gap-3">
                    <GhostLink href="/webhooks">Cancel</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Update webhook' : 'Create webhook'}
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
