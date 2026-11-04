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

export type LocaleOption = {
    value: string;
    label: string;
    native: string;
};

export type SharedLocaleProps = {
    locale?: string;
    localeOptions?: LocaleOption[];
    translations?: Record<string, string>;
    fallbackTranslations?: Record<string, string>;
    isRtl?: boolean;
    localeUpdateUrl?: string;
};

export type SharedPageProps = SharedLocaleProps & {
    user?: User | null;
    organization?: Organization | null;
    brand?: Brand;
    flash?: {
        status?: string | null;
        success?: string | null;
        error?: string | null;
    };
    modules?: ConsoleModule[];
    activeModule?: string | null;
    routes?: ConsoleRoutes;
    abilities?: {
        edit?: boolean;
        admin?: boolean;
    };
    errors?: Record<string, string | string[]>;
};
