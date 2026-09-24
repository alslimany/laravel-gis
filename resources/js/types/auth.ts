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

export type NavEntry = {
    label: string;
    href: string;
    icon: string;
    group: string;
    match?: string;
};

export type ConsoleRoutes = Record<string, string | null | undefined>;
