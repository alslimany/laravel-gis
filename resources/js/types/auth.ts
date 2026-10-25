export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    is_admin?: boolean;
    roles?: string[];
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type Brand = {
    name: string;
    logoUrl?: string | null;
    primaryColor?: string;
};

export type Organization = {
    id: number;
    name: string;
    description?: string | null;
};

export type ConsoleModuleItem = {
    label: string;
    href: string;
    icon: string;
    match?: string;
};

export type ConsoleModuleSection = {
    label: string;
    items: ConsoleModuleItem[];
};

export type ConsoleModule = {
    key: string;
    label: string;
    description?: string | null;
    icon: string;
    accent: string;
    kind: 'core' | 'extension' | 'admin';
    href: string;
    sections: ConsoleModuleSection[];
};

export type ConsoleRoutes = Record<string, string | null | undefined>;
