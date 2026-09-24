import { useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { Field, Heading, PrimaryButton, TextInput } from '../../Components/ui';

export default function ResetPassword({ token, email }) {
    const form = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    function submit(event) {
        event.preventDefault();
        form.post('/password/reset');
    }

    return (
        <GuestLayout title="Reset password">
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">Choose a new password</Heading>
                <Field label="Email" error={form.errors.email}>
                    <TextInput id="email" type="email" value={form.data.email} required onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <TextInput id="password" type="password" value={form.data.password} required autoFocus onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <Field label="Confirm password">
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        value={form.data.password_confirmation}
                        required
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    />
                </Field>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Reset password
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
