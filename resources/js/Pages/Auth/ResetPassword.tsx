import { useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import GuestLayout from '@/layouts/guest-layout';
import { Field, Heading, PrimaryButton, TextInput } from '@/components/gis';

export default function ResetPassword({ token, email }) {
    const { t } = useTranslation();
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
        <GuestLayout title={t('auth.reset_password')}>
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">{t('auth.choose_password')}</Heading>
                <Field label={t('forms.email')} error={form.errors.email}>
                    <TextInput id="email" type="email" value={form.data.email} required onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <Field label={t('forms.password')} error={form.errors.password}>
                    <TextInput id="password" type="password" value={form.data.password} required autoFocus onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <Field label={t('forms.confirm_password')}>
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
