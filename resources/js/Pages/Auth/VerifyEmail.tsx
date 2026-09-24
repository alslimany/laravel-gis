import { useForm, usePage } from '@inertiajs/react';
import GuestLayout from '@/layouts/guest-layout';
import { Heading, PrimaryButton } from '@/components/gis';

export default function VerifyEmail() {
    const { flash } = usePage().props;
    const form = useForm({});

    return (
        <GuestLayout title="Verify email">
            <div className="space-y-4">
                <Heading as="h2">Verify your email address</Heading>
                {flash?.status ? <p className="text-ok">A fresh verification link has been sent to your email address.</p> : null}
                <p className="text-muted-foreground">Before proceeding, please check your email for a verification link.</p>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/email/resend');
                    }}
                >
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Resend verification email
                    </PrimaryButton>
                </form>
            </div>
        </GuestLayout>
    );
}
