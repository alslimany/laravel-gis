import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import { LogOut, Moon, Sun } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTheme } from '@/lib/theme';
import type { User } from '@/types';

export function UserMenuContent({ user }: { user: User }) {
    const { t } = useTranslation();
    const cleanup = useMobileNavigation();
    const { routes } = usePage().props;
    const { theme, toggle } = useTheme();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                    <UserInfo user={user} showEmail />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem
                    onClick={() => {
                        cleanup();
                        toggle();
                    }}
                >
                    {theme === 'dark' ? <Sun /> : <Moon />}
                    {theme === 'dark' ? t('common.light_mode') : t('common.dark_mode')}
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                onClick={() => {
                    cleanup();
                    if (routes.logout) {
                        router.post(routes.logout);
                    }
                }}
            >
                <LogOut />
                {t('common.log_out')}
            </DropdownMenuItem>
        </>
    );
}
