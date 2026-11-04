import { useForm } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
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
    const { t } = useTranslation();
    const [over, setOver] = useState(false);

    return (
        <Field label={t('common.files')} hint={hint} error={error}>
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
                <span className="text-sm font-medium">{t('imports.drop_files')}</span>
                <span className="text-xs text-muted-foreground">{names || t('imports.nothing_selected')}</span>
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
                            <span dir="ltr" className="shrink-0 tabular-nums text-muted-foreground">{bytes(file.size)}</span>
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
    const { t } = useTranslation();
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
        <AppLayout title={t('imports.add_data')}>
            <PageHeader title={t('imports.add_data')} action={<GhostLink href="/imports">{t('actions.back')}</GhostLink>} />
            <div role="tablist" aria-label={__('imports.what_to_add')} className="inline-flex w-fit rounded-lg bg-muted p-1">
                <a
                    href="/imports/create"
                    role="tab"
                    aria-selected={!imageryMode}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium no-underline',
                        imageryMode ? 'text-muted-foreground hover:text-foreground' : 'bg-background text-foreground shadow-sm',
                    )}
                >
                    {featureLabel === 'Feature data' ? __('imports.feature_data') : featureLabel}
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
                    {imageryLabel === 'Imagery' ? __('imports.imagery') : imageryLabel}
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
                            {__('imports.imagery_help')}
                        </p>
                        <Field label={t('solution_packs.layer_name')} error={imagery.errors.name}>
                            <TextInput value={imagery.data.name} onChange={(event) => imagery.setData('name', event.target.value)} placeholder={__('imports.imagery_placeholder')} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('common.captured_on')} error={imagery.errors.acquired_at}>
                                <TextInput type="date" value={imagery.data.acquired_at} required onChange={(event) => imagery.setData('acquired_at', event.target.value)} />
                            </Field>
                            <Field label={t('imports.add_to_imagery')} error={imagery.errors.layer_id}>
                                <Select value={imagery.data.layer_id} onChange={(event) => imagery.setData('layer_id', event.target.value)}>
                                    <option value="">{__('imports.new_imagery_layer')}</option>
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
                            hint={__('imports.imagery_hint', { mb: imageryMb })}
                            error={fileError(imagery.errors)}
                            names={names}
                            files={imagery.data.files}
                            onPick={(files) => onFiles(imagery, files)}
                        />
                        {imageryHint ? <p className="max-w-prose text-sm text-muted-foreground">{imageryHint}</p> : null}
                        <div className="flex gap-3">
                            <GhostLink href="/imports">{t('actions.cancel')}</GhostLink>
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
                            {__('imports.feature_help')}
                        </p>
                        <FileDrop
                            accept=".zip,.shp,.shx,.dbf,.prj,.cpg,.geojson,.json,.kml,.kmz,.csv,.xlsx,.xls"
                            hint={__('imports.feature_hint', { mb: featureMb })}
                            error={fileError(feature.errors)}
                            names={names}
                            files={feature.data.files}
                            onPick={(files) => onFiles(feature, files)}
                        />
                        <div className="flex gap-3">
                            <GhostLink href="/imports">{t('actions.cancel')}</GhostLink>
                            <PrimaryButton type="submit" disabled={feature.processing || feature.data.files.length === 0}>
                                {feature.processing ? t('imports.importing') : t('imports.import_dataset')}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </Panel>
        </AppLayout>
    );
}
