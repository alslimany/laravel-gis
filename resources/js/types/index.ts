export type * from './auth';
export type * from './navigation';
export type * from './ui';

import type { ReactNode } from 'react';
import type { BreadcrumbItem } from './navigation';

export type AppLayoutProps = {
    children: ReactNode;
    title?: string;
    breadcrumbs?: BreadcrumbItem[];
};
