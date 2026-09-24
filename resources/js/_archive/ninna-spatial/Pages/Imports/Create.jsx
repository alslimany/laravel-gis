import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { HStack } from '@ninna-ui/layout';
import AppLayout from '../../Layouts/AppLayout';
import { Field, GhostLink, PageHeader, PrimaryButton, PrimaryLink, Select, TextInput } from '../../Components/ui';

export default function Create({ kind = 'feature', imageryLayers = [], featureLabel = 'Feature data', imageryLabel = 'Imagery', imageryHint = '', featureMb = 0, imageryMb = 0 }) {
    const feature = useForm({ files: [] });
    const imagery = useForm({
        _kind: 'imagery',
        name: '',
        acquired_at: new Date().toISOString().slice(0, 10),
        layer_id: '',
        files: [],
    });
    const [names, setNames] = useState('');

    function onFiles(form, event) {
        const files = Array.from(event.target.files || []);
        form.setData('files', files);
        setNames(files.map((file) => file.name).join(', '));
    }

    return (
        <AppLayout title="Add data">
            <PageHeader title="Add data" action={<GhostLink href="/imports">Back</GhostLink>} />
            <HStack role="tablist" aria-label="What to add">
                {kind === 'imagery' ? (
                    <GhostLink href="/imports/create">{featureLabel}</GhostLink>
                ) : (
                    <PrimaryLink href="/imports/create">{featureLabel}</PrimaryLink>
                )}
                {kind === 'imagery' ? (
                    <PrimaryLink href="/imports/create?kind=imagery">{imageryLabel}</PrimaryLink>
                ) : (
                    <GhostLink href="/imports/create?kind=imagery">{imageryLabel}</GhostLink>
                )}
            </HStack>
            {kind === 'imagery' ? (
                <form
                    className="max-w-2xl space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        imagery.post('/imports/imagery', { forceFormData: true });
                    }}
                >
                    <p className="text-muted">
                        Publish a georeferenced scene above the satellite basemap. A GeoTIFF, or a JPEG/PNG with its world file and .prj, is prepared as map tiles.
                    </p>
                    <Field label="Layer name">
                        <TextInput value={imagery.data.name} onChange={(event) => imagery.setData('name', event.target.value)} placeholder="Coast, September 2026" />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Captured on" error={imagery.errors.acquired_at}>
                            <TextInput type="date" value={imagery.data.acquired_at} required onChange={(event) => imagery.setData('acquired_at', event.target.value)} />
                        </Field>
                        <Field label="Add to an imagery layer">
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
                    <Field label="Georeferenced image" hint={`GeoTIFF, zip, or JPEG/PNG with a world file. Up to ${imageryMb} MB.`}>
                        <input
                            type="file"
                            required
                            multiple
                            accept=".tif,.tiff,.jpg,.jpeg,.png,.zip,.jgw,.jpgw,.pgw,.pngw,.wld,.tfw,.prj"
                            className="block w-full"
                            onChange={(event) => onFiles(imagery, event)}
                        />
                    </Field>
                    {names ? <p className="font-mono text-muted">{names}</p> : null}
                    <p className="text-muted">{imageryHint}</p>
                    <div className="flex gap-3">
                        <GhostLink href="/imports">Cancel</GhostLink>
                        <PrimaryButton type="submit" disabled={imagery.processing || imagery.data.files.length === 0}>
                            Publish imagery
                        </PrimaryButton>
                    </div>
                </form>
            ) : (
                <form
                    className="max-w-2xl space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        feature.post('/imports', { forceFormData: true });
                    }}
                >
                    <p className="text-muted">
                        Drop a dataset the way a feature layer is published. A zipped shapefile, KML, KMZ, GeoJSON, CSV, or Excel file is loaded into PostGIS.
                    </p>
                    <Field label="Package" hint={`Shapefile zip, KML, GeoJSON, CSV, or Excel. Up to ${featureMb} MB.`}>
                        <input
                            type="file"
                            required
                            multiple
                            accept=".zip,.shp,.shx,.dbf,.prj,.cpg,.geojson,.json,.kml,.kmz,.csv,.xlsx,.xls"
                            className="block w-full"
                            onChange={(event) => onFiles(feature, event)}
                        />
                    </Field>
                    {names ? <p className="font-mono text-muted">{names}</p> : null}
                    <div className="flex gap-3">
                        <GhostLink href="/imports">Cancel</GhostLink>
                        <PrimaryButton type="submit" disabled={feature.processing || feature.data.files.length === 0}>
                            Publish layer
                        </PrimaryButton>
                    </div>
                </form>
            )}
        </AppLayout>
    );
}
