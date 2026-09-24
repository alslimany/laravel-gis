import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/layouts/guest-layout';
import { Field, Heading, PrimaryButton, TextInput } from '@/components/gis';

export default function Login() {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event) {
        event.preventDefault();
        form.post('/login');
    }

    return (
        <GuestLayout title="Sign in">
            <form onSubmit={submit} className="space-y-4">
                <Heading as="h2">Sign in</Heading>
                <Field label="Email" error={form.errors.email}>
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={form.data.email}
                        autoComplete="email"
                        autoFocus
                        required
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        autoComplete="current-password"
                        required
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                </Field>
                <label className="flex items-center gap-2 text-muted-foreground">
                    <input
                        id="remember"
                        type="checkbox"
                        checked={form.data.remember}
                        onChange={(event) => form.setData('remember', event.target.checked)}
                    />
                    Remember me
                </label>
                <div className="flex items-center justify-between gap-3">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Sign in
                    </PrimaryButton>
                    <Link href="/password/reset" className="text-muted-foreground hover:text-foreground">
                        Forgot password
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
