import { useForm, usePage } from '@inertiajs/react';
import GuestLayout from '@/layouts/guest-layout';
import { Field, Heading, PrimaryButton, TextInput } from '@/components/gis';

export default function ForgotPassword() {
    const { flash } = usePage().props;
    const form = useForm({ email: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/password/email');
    }

    return (
        <GuestLayout title="Reset password">
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">Reset password</Heading>
                {flash?.status ? <p className="text-ok">{flash.status}</p> : null}
                <Field label="Email" error={form.errors.email}>
                    <TextInput id="email" type="email" value={form.data.email} required autoFocus onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Send password reset link
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
