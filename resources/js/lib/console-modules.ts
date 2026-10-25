import {
    Building2,
    ChartColumn,
    CloudRain,
    FolderKanban,
    FormInput,
    Globe,
    HardHat,
    House,
    KeyRound,
    Layers,
    LayoutDashboard,
    LayoutGrid,
    Library,
    Map,
    Package,
    Puzzle,
    ShieldCheck,
    Upload,
    Users,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import { usePage } from '@inertiajs/react';
import type { ConsoleModule } from '@/types';

const ICONS: Record<string, LucideIcon> = {
    admin: ShieldCheck,
    apps: LayoutGrid,
    catalog: Library,
    'cloud-rain': CloudRain,
    core: Globe,
    dashboard: LayoutDashboard,
    dashboards: ChartColumn,
    extensions: Puzzle,
    forms: FormInput,
    groups: UsersRound,
    'hard-hat': HardHat,
    home: House,
    imports: Upload,
    layers: Layers,
    maps: Map,
    organization: Building2,
    packs: Package,
    projects: FolderKanban,
    tokens: KeyRound,
    users: Users,
};

/**
 * Tailwind classes per module accent. Kept as literal strings so the
 * compiler can see them; unknown accents fall back to the theme primary.
 */
const ACCENTS: Record<string, { mark: string; bar: string }> = {
    primary: { mark: 'bg-primary text-primary-foreground', bar: 'bg-primary' },
    blue: { mark: 'bg-blue-600 text-white', bar: 'bg-blue-600' },
    cyan: { mark: 'bg-cyan-600 text-white', bar: 'bg-cyan-600' },
    emerald: { mark: 'bg-emerald-600 text-white', bar: 'bg-emerald-600' },
    amber: { mark: 'bg-amber-500 text-white', bar: 'bg-amber-500' },
    orange: { mark: 'bg-orange-600 text-white', bar: 'bg-orange-600' },
    rose: { mark: 'bg-rose-600 text-white', bar: 'bg-rose-600' },
    violet: { mark: 'bg-violet-600 text-white', bar: 'bg-violet-600' },
    slate: { mark: 'bg-slate-600 text-white', bar: 'bg-slate-500' },
};

export function moduleIcon(name: string | undefined): LucideIcon {
    return (name && ICONS[name]) || Layers;
}

export function moduleAccent(accent: string | undefined): { mark: string; bar: string } {
    return (accent && ACCENTS[accent]) || ACCENTS.primary;
}

export function useConsoleModules(): { modules: ConsoleModule[]; active: ConsoleModule | null } {
    const { modules = [], activeModule } = usePage().props as {
        modules?: ConsoleModule[];
        activeModule?: string | null;
    };

    return {
        modules,
        active: modules.find((module) => module.key === activeModule) ?? modules[0] ?? null,
    };
}
