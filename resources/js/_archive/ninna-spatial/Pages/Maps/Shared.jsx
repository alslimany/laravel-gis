import { Heading } from '@ninna-ui/primitives';
import { Head } from '@inertiajs/react';
import SharedMap from '../../Components/SharedMap';

export default function Shared({ map }) {
    return (
        <div className="relative h-screen bg-canvas text-copy">
            <Head title={map.name} />
            <div className="pointer-events-none absolute left-4 top-4 z-10 max-w-sm rounded border border-line bg-panel/90">
                <Heading as="h1">{map.name}</Heading>
                {map.description ? <p className="mt-1 text-muted">{map.description}</p> : null}
            </div>
            <SharedMap map={map} />
        </div>
    );
}
