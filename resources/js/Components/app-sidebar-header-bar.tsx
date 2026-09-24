import { Link, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Button } from '@/components/ui/button';
import { useTheme } from '@/lib/theme';
import type { BreadcrumbItem } from '@/types';

export function AppSidebarHeaderBar({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItem[];
}) {
    const { routes } = usePage().props;
    const { theme, toggle } = useTheme();

    return (
        <div className="flex items-center justify-between border-b border-sidebar-border/50 pr-4">
            <AppSidebarHeader breadcrumbs={breadcrumbs} />
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                    onClick={toggle}
                >
                    {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                </Button>
                {routes.mapBuilder ? (
                    <Button asChild size="sm">
                        <Link href={routes.mapBuilder}>Open map builder</Link>
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
