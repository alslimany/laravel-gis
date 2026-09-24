import GuestLayout from '@/layouts/guest-layout';
import type { ReactNode } from 'react';

/** Thin alias kept for starter-kit shaped imports; GIS auth pages use GuestLayout directly. */
export default function AuthLayout({
    title = '',
    children,
}: {
    title?: string;
    description?: string;
    children: ReactNode;
}) {
    return <GuestLayout title={title}>{children}</GuestLayout>;
}
