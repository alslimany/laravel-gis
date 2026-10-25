import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Empty, PageHeader, Panel, Pill } from '@/components/gis';
import { ModuleMark } from '@/components/module-switcher';
import { Button } from '@/components/ui/button';

type Extension = {
    key: string;
    name: string;
    description?: string | null;
    icon: string;
    accent: string;
    version?: string | null;
    installed: boolean;
    enabled: boolean;
    updateUrl: string;
};

function status(extension: Extension) {
    if (!extension.installed) {
        return <Pill>Not installed</Pill>;
    }

    return <Pill live={extension.enabled}>{extension.enabled ? 'On' : 'Off'}</Pill>;
}

export default function Index({ extensions = [] }: { extensions?: Extension[] }) {
    return (
        <AppLayout title="Extensions" breadcrumbs={[{ title: 'Extensions', href: '/organization/extensions' }]}>
            <PageHeader title="Extensions" />
            <p className="max-w-2xl text-sm text-muted-foreground">
                Turn extensions on to add their module to everyone's switcher in this organization. Turning one off hides
                its module and pages; its data is kept.
            </p>

            {extensions.length === 0 ? (
                <Empty>No extensions are available on this server.</Empty>
            ) : (
                <div className="grid gap-3">
                    {extensions.map((extension) => (
                        <Panel key={extension.key} className={extension.installed ? '' : 'opacity-70'}>
                            <div className="flex flex-wrap items-center gap-4">
                                <ModuleMark module={extension} className="size-10 [&_svg]:size-5" />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">{extension.name}</span>
                                        {status(extension)}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {extension.description || extension.key}
                                        {extension.version ? ` · v${extension.version}` : ''}
                                    </p>
                                    {!extension.installed ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            A platform administrator must install it before it can be turned on.
                                        </p>
                                    ) : null}
                                </div>
                                {extension.installed ? (
                                    <Button asChild variant={extension.enabled ? 'outline' : 'default'} size="sm">
                                        <Link
                                            href={extension.updateUrl}
                                            method="put"
                                            data={{ enabled: !extension.enabled }}
                                            as="button"
                                            preserveScroll
                                        >
                                            {extension.enabled ? 'Turn off' : 'Turn on'}
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        </Panel>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
