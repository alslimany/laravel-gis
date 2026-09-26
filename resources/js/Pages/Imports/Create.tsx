import { useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';
import { bytes } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Field, GhostLink, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';

type ImageryLayer = { id: number; name: string };

type CreateProps = {
    kind?: string;
    imageryLayers?: ImageryLayer[];
    featureLabel?: string;
    imageryLabel?: string;
    imageryHint?: string;
    featureMb?: number;
    imageryMb?: number;
};

function fileError(errors: Record<string, string>) {
    const entry = Object.entries(errors).find(([key, value]) => (
        typeof value === 'string' && (key === 'file' || key === 'files' || key.startsWith('files.'))
    ));

    return entry?.[1] ?? null;
}

function FileDrop({
    accept,
    hint,
    error,
    names,
    files,
    onPick,
}: {
    accept: string;
    hint: string;
    error?: string | null;
    names: string;
    files: File[];
    onPick: (files: File[]) => void;
}) {
    const [over, setOver] = useState(false);

    return (
        <Field label="Files" hint={hint} error={error}>
            <label
                className={cn(
                    'flex cursor-pointer flex-col items-center gap-1 rounded-lg border border-dashed px-6 py-8 text-center transition-colors',
                    over ? 'border-foreground bg-muted' : 'border-input bg-muted/40 hover:bg-muted/70',
                )}
                onDragOver={(event) => {
                    event.preventDefault();
                    setOver(true);
                }}
                onDragLeave={() => setOver(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setOver(false);
                    onPick(Array.from(event.dataTransfer.files || []));
                }}
            >
                <Upload className="size-5 text-muted-foreground" />
                <span className="text-sm font-medium">Drop files here, or browse</span>
                <span className="text-xs text-muted-foreground">{names || 'Nothing selected'}</span>
                <input
                    type="file"
                    multiple
                    accept={accept}
                    className="sr-only"
                    onChange={(event) => onPick(Array.from(event.target.files || []))}
                />
            </label>
            {files.length > 0 ? (
                <ul className="divide-y rounded-md border text-sm">
                    {files.map((file) => (
                        <li key={`${file.name}-${file.size}`} className="flex items-center justify-between gap-3 px-3 py-2">
                            <span className="min-w-0 truncate">{file.name}</span>
                            <span className="shrink-0 tabular-nums text-muted-foreground">{bytes(file.size)}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </Field>
    );
}

export default function Create({
    kind = 'feature',
    imageryLayers = [],
    featureLabel = 'Feature data',
    imageryLabel = 'Imagery',
    imageryHint = '',
    featureMb = 0,
    imageryMb = 0,
}: CreateProps) {
    const feature = useForm({ files: [] as File[] });
    const imagery = useForm({
        _kind: 'imagery',
        name: '',
        acquired_at: new Date().toISOString().slice(0, 10),
        layer_id: '',
        files: [] as File[],
    });
    const [names, setNames] = useState('');
    const imageryMode = kind === 'imagery';

    function onFiles(form: { setData: (key: string, value: File[]) => void }, files: File[]) {
        form.setData('files', files);
        setNames(files.map((file) => file.name).join(', '));
    }

    return (
        <AppLayout title="Add data">
            <PageHeader title="Add data" action={<GhostLink href="/imports">Back</GhostLink>} />
            <div role="tablist" aria-label="What to add" className="inline-flex w-fit rounded-lg bg-muted p-1">
                <a
                    href="/imports/create"
                    role="tab"
                    aria-selected={!imageryMode}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium no-underline',
                        imageryMode ? 'text-muted-foreground hover:text-foreground' : 'bg-background text-foreground shadow-sm',
                    )}
                >
                    {featureLabel}
                </a>
                <a
                    href="/imports/create?kind=imagery"
                    role="tab"
                    aria-selected={imageryMode}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium no-underline',
                        imageryMode ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {imageryLabel}
                </a>
            </div>
            <Panel className="max-w-2xl">
                {imageryMode ? (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            imagery.post('/imports/imagery', { forceFormData: true });
                        }}
                    >
                        <p className="max-w-prose text-sm text-muted-foreground">
                            Publish a georeferenced scene above the satellite basemap. A GeoTIFF, or a JPEG/PNG with its world file and .prj, is prepared as map tiles.
                        </p>
                        <Field label="Layer name" error={imagery.errors.name}>
                            <TextInput value={imagery.data.name} onChange={(event) => imagery.setData('name', event.target.value)} placeholder="Coast, September 2026" />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Captured on" error={imagery.errors.acquired_at}>
                                <TextInput type="date" value={imagery.data.acquired_at} required onChange={(event) => imagery.setData('acquired_at', event.target.value)} />
                            </Field>
                            <Field label="Add to an imagery layer" error={imagery.errors.layer_id}>
                                <Select value={imagery.data.layer_id} onChange={(event) => imagery.setData('layer_id', event.target.value)}>
                                    <option value="">New imagery layer</option>
                                    {imageryLayers.map((layer) => (
                                        <option key={layer.id} value={layer.id}>
                                            {layer.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <FileDrop
                            accept=".tif,.tiff,.jpg,.jpeg,.png,.zip,.jgw,.jpgw,.pgw,.pngw,.wld,.tfw,.prj"
                            hint={`GeoTIFF, zip, or JPEG/PNG with a world file. Up to ${imageryMb} MB.`}
                            error={fileError(imagery.errors)}
                            names={names}
                            files={imagery.data.files}
                            onPick={(files) => onFiles(imagery, files)}
                        />
                        {imageryHint ? <p className="max-w-prose text-sm text-muted-foreground">{imageryHint}</p> : null}
                        <div className="flex gap-3">
                            <GhostLink href="/imports">Cancel</GhostLink>
                            <PrimaryButton type="submit" disabled={imagery.processing || imagery.data.files.length === 0}>
                                {imagery.processing ? 'Publishing…' : 'Publish imagery'}
                            </PrimaryButton>
                        </div>
                    </form>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            feature.post('/imports', { forceFormData: true });
                        }}
                    >
                        <p className="max-w-prose text-sm text-muted-foreground">
                            Drop a dataset the way a feature layer is published. A zipped shapefile, KML, KMZ, GeoJSON, CSV, or Excel file is loaded into PostGIS.
                        </p>
                        <FileDrop
                            accept=".zip,.shp,.shx,.dbf,.prj,.cpg,.geojson,.json,.kml,.kmz,.csv,.xlsx,.xls"
                            hint={`Shapefile zip, KML, GeoJSON, CSV, or Excel. Up to ${featureMb} MB.`}
                            error={fileError(feature.errors)}
                            names={names}
                            files={feature.data.files}
                            onPick={(files) => onFiles(feature, files)}
                        />
                        <div className="flex gap-3">
                            <GhostLink href="/imports">Cancel</GhostLink>
                            <PrimaryButton type="submit" disabled={feature.processing || feature.data.files.length === 0}>
                                {feature.processing ? 'Importing…' : 'Import dataset'}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </Panel>
        </AppLayout>
    );
}
