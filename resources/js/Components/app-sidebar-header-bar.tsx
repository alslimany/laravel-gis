import { Moon, Sun } from 'lucide-react';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';
import type { BreadcrumbItem } from '@/types';

export function AppSidebarHeaderBar({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItem[];
}) {
    const { theme, toggle } = useTheme();
    const { t } = useTranslation();

    return (
        <div className="flex items-center justify-between border-b border-sidebar-border/50 pe-4">
            <AppSidebarHeader breadcrumbs={breadcrumbs} />
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={
                        theme === 'dark' ? t('common.switch_to_light') : t('common.switch_to_dark')
                    }
                    onClick={toggle}
                >
                    {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                </Button>
                <LanguageSwitcher />
            </div>
        </div>
    );
}
