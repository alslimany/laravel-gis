import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    title?: string;
    description?: string;
};

export type SidebarLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};
