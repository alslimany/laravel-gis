import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { count, when } from '../../lib/format';
import { GhostLink, PageHeader, Pill, PrimaryButton, tdMuted } from '../../Components/ui';

export default function Show({ layer }) {
    const { abilities } = usePage().props;

    function publish() {
        router.post(layer.published ? `/layers/${layer.id}/unpublish` : `/layers/${layer.id}/publish`);
    }

    return (
        <AppLayout title={layer.name}>
            <PageHeader
                title={layer.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        {abilities?.edit ? (
                            <PrimaryButton type="button" onClick={publish}>
                                {layer.published ? 'Unpublish' : 'Publish'}
                            </PrimaryButton>
                        ) : null}
                        {abilities?.edit ? <GhostLink href={`/layers/${layer.id}/edit`}>Edit</GhostLink> : null}
                        <GhostLink href={`/layers/${layer.id}/attributes`}>Attributes</GhostLink>
                        <GhostLink href={`/maps/builder?layer=${layer.id}`}>Open in map</GhostLink>
                        <GhostLink href="/layers">Back</GhostLink>
                    </div>
                }
            />
            <dl className="grid max-w-3xl gap-3 sm:grid-cols-2">
                <div>
                    <dt className={tdMuted}>Status</dt>
                    <dd className="mt-1">
                        <Pill live={layer.published}>{layer.published ? 'Published' : 'Draft'}</Pill>
                    </dd>
                </div>
                <div>
                    <dt className={tdMuted}>Project</dt>
                    <dd className="mt-1">{layer.project?.name || '—'}</dd>
                </div>
                <div>
                    <dt className={tdMuted}>Table</dt>
                    <dd className="mt-1 font-mono">{layer.table_name}</dd>
                </div>
                <div>
                    <dt className={tdMuted}>Geometry</dt>
                    <dd className="mt-1 font-mono">{layer.geometry_type || 'Not detected'}</dd>
                </div>
                <div>
                    <dt className={tdMuted}>Features</dt>
                    <dd className="mt-1 font-mono">{count(layer.feature_count)}</dd>
                </div>
                <div>
                    <dt className={tdMuted}>Created by</dt>
                    <dd className="mt-1">{layer.user?.name || '—'}</dd>
                </div>
                {layer.published ? (
                    <>
                        <div>
                            <dt className={tdMuted}>GeoServer layer</dt>
                            <dd className="mt-1 font-mono">{layer.geoserver_layer_name || '—'}</dd>
                        </div>
                        <div>
                            <dt className={tdMuted}>Workspace</dt>
                            <dd className="mt-1 font-mono">{layer.geoserver_workspace || '—'}</dd>
                        </div>
                    </>
                ) : null}
                <div className="sm:col-span-2">
                    <dt className={tdMuted}>Description</dt>
                    <dd className="mt-1 text-muted">{layer.description || '—'}</dd>
                </div>
                <div>
                    <dt className={tdMuted}>Created</dt>
                    <dd className="mt-1">{when(layer.created_at)}</dd>
                </div>
            </dl>
            <p className="mt-6">
                <Link href={`/export/layer/${layer.id}/geojson`} className="font-medium text-primary hover:underline">
                    Download GeoJSON
                </Link>
            </p>
        </AppLayout>
    );
}
