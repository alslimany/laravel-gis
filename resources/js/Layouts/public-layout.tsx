import { Head, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useTheme } from '@/lib/theme';

export default function PublicLayout({ title, children }: { title?: string; children: ReactNode }) {
    const { brand } = usePage().props;
    const name = brand?.name || 'Lumina GIS';
    const { theme, toggle } = useTheme();

    return (
        <div className="min-h-screen bg-background text-foreground">
            {title ? <Head title={title} /> : null}
            <header className="border-b border-border bg-muted/40">
                <div className="flex items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-2">
                        <div className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                            <AppLogoIcon className="size-5" />
                        </div>
                        <span className="text-sm font-medium">{name}</span>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                        onClick={toggle}
                    >
                        {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                    </Button>
                </div>
            </header>
            <main className="p-4">{children}</main>
        </div>
    );
}
