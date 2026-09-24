import { useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { Field, Heading, PrimaryButton, TextInput } from '../../Components/ui';

export default function ConfirmPassword() {
    const form = useForm({ password: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/password/confirm');
    }

    return (
        <GuestLayout title="Confirm password">
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">Confirm password</Heading>
                <p className="text-muted">Please confirm your password before continuing.</p>
                <Field label="Password" error={form.errors.password}>
                    <TextInput id="password" type="password" value={form.data.password} required autoFocus onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Confirm
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
