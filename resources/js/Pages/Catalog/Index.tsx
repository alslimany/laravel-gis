import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { count } from '@/lib/format';
import { Heading, PageHeader, Panel, PrimaryButton, TextInput } from '@/components/gis';

function ResultList({ title, items, hrefFor }) {
    return (
        <Panel>
            <Heading as="h2" className="border-b border-border">
                {title} ({items.length})
            </Heading>
            <ul>
                {items.length === 0 ? <li className="px-3 py-3 text-muted-foreground">No matches.</li> : null}
                {items.map((item) => (
                    <li key={item.id} className="flex items-center justify-between border-b border-border/70">
                        <span>
                            {item.name}
                            <span className="mt-0.5 block text-muted-foreground">{item.geometry_type || item.description || ''}</span>
                        </span>
                        {hrefFor ? (
                            <Link href={hrefFor(item)} className="font-medium text-link">
                                Open
                            </Link>
                        ) : null}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

export default function Index({ q = '', layers = [], maps = [], forms = [], dashboards = [] }) {
    const [query, setQuery] = useState(q);

    return (
        <AppLayout title="Catalog">
            <PageHeader title="Catalog" />
            <form
                className="mb-4 flex max-w-xl gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/catalog', { q: query }, { preserveState: true });
                }}
            >
                <TextInput value={query} placeholder="Search layers, maps, forms, dashboards" onChange={(event) => setQuery(event.target.value)} />
                <PrimaryButton type="submit">Search</PrimaryButton>
            </form>
            <div className="grid gap-4 lg:grid-cols-2">
                <ResultList title="Layers" items={layers} hrefFor={(item) => `/layers/${item.id}`} />
                <ResultList title="Maps" items={maps} hrefFor={(item) => `/maps/${item.id}`} />
                <ResultList title="Forms" items={forms} hrefFor={(item) => `/forms/${item.id}`} />
                <ResultList title="Dashboards" items={dashboards} hrefFor={(item) => `/dashboards/${item.id}`} />
            </div>
            <p className="sr-only">{count(layers.length + maps.length)}</p>
        </AppLayout>
    );
}
