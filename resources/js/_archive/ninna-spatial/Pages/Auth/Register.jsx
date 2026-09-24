import { useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { Field, Heading, PrimaryButton, TextInput } from '../../Components/ui';

export default function Register() {
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event) {
        event.preventDefault();
        form.post('/register');
    }

    return (
        <GuestLayout title="Register">
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">Register</Heading>
                <Field label="Name" error={form.errors.name}>
                    <TextInput id="name" value={form.data.name} required autoFocus onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Email" error={form.errors.email}>
                    <TextInput id="email" type="email" value={form.data.email} required autoComplete="email" onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <TextInput id="password" type="password" value={form.data.password} required autoComplete="new-password" onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <Field label="Confirm password" error={form.errors.password_confirmation}>
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        value={form.data.password_confirmation}
                        required
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    />
                </Field>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Register
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
