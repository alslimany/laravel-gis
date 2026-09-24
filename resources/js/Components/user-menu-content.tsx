import { Link, router, usePage } from '@inertiajs/react';
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
    const cleanup = useMobileNavigation();
    const { routes } = usePage().props;
    const { theme, toggle } = useTheme();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
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
                    {theme === 'dark' ? <Sun className="mr-2" /> : <Moon className="mr-2" />}
                    {theme === 'dark' ? 'Light mode' : 'Dark mode'}
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
                <LogOut className="mr-2" />
                Log out
            </DropdownMenuItem>
        </>
    );
}
