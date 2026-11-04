import { Head } from '@inertiajs/react';
import SharedMap from '@/components/SharedMap';
import { RtlSync } from '@/components/rtl-sync';
import { Heading } from '@/components/gis';
import { useTranslation } from '@/lib/i18n';

export default function Shared({ map }) {
    const { t } = useTranslation();

    return (
        <RtlSync>
            <div className="relative h-screen bg-background text-foreground">
                <Head title={map.name || t('maps.map')} />
                <div className="pointer-events-none absolute start-4 top-4 z-10 max-w-sm rounded-md border border-border bg-card/90 p-3 shadow-sm">
                    <Heading as="h1">{map.name}</Heading>
                    {map.description ? <p className="mt-1 text-muted-foreground">{map.description}</p> : null}
                </div>
                <SharedMap map={map} />
            </div>
        </RtlSync>
    );
}
