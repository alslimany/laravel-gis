import { Head } from '@inertiajs/react';
import SharedMap from '@/components/SharedMap';
import { Heading } from '@/components/gis';

export default function Shared({ map }) {
    return (
        <div className="relative h-screen bg-background text-foreground">
            <Head title={map.name} />
            <div className="pointer-events-none absolute left-4 top-4 z-10 max-w-sm rounded-md border border-border bg-card/90 p-3 shadow-sm">
                <Heading as="h1">{map.name}</Heading>
                {map.description ? <p className="mt-1 text-muted-foreground">{map.description}</p> : null}
            </div>
            <SharedMap map={map} />
        </div>
    );
}
