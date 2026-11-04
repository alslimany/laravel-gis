import { useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import GuestLayout from '@/layouts/guest-layout';
import { Field, Heading, PrimaryButton, TextInput } from '@/components/gis';

export default function ConfirmPassword() {
    const { t } = useTranslation();
    const form = useForm({ password: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/password/confirm');
    }

    return (
        <GuestLayout title={t('forms.confirm_password')}>
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">{t('forms.confirm_password')}</Heading>
                <p className="text-muted-foreground">Please confirm your password before continuing.</p>
                <Field label={t('forms.password')} error={form.errors.password}>
                    <TextInput id="password" type="password" value={form.data.password} required autoFocus onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Confirm
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
