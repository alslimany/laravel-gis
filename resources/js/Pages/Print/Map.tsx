import AppLayout from '@/layouts/app-layout';
import { GhostLink, Heading, PageHeader } from '@/components/gis';

export default function Map({ map, printTitle, legend = [], mapImage, scaleDenominator, printedAt, logoDataUri }) {
    return (
        <AppLayout title={printTitle || map.name}>
            <PageHeader title={printTitle || map.name} action={<GhostLink href={`/maps/${map.id}`}>Back</GhostLink>} />
            <article className="mx-auto max-w-4xl border border-border bg-card p-6">
                <header className="mb-4 flex items-center justify-between">
                    <div>
                        <Heading as="h1" >{printTitle || map.name}</Heading>
                        {map.description ? <p className="text-muted-foreground">{map.description}</p> : null}
                    </div>
                    {logoDataUri ? <img src={logoDataUri} alt=""  /> : null}
                </header>
                {mapImage ? <img src={mapImage} alt="" className="mb-4 w-full border border-border" /> : <div className="mb-4 h-64 border border-dashed border-border" />}
                <ul className="space-y-1">
                    {legend.map((item) => (
                        <li key={item.label} className="flex items-center gap-2">
                            <span className="inline-block h-3 w-3" style={{ background: item.color }} />
                            {item.label}
                        </li>
                    ))}
                </ul>
                <p className="mt-4 font-mono text-muted-foreground">
                    {scaleDenominator ? `1:${scaleDenominator}` : 'Scale from the map builder'} · {printedAt}
                </p>
            </article>
        </AppLayout>
    );
}
