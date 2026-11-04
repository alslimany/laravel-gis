import { useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import AdvisoryBanner from './AdvisoryBanner';
import { HAZARD_LAYER_SETTING_KEYS, hazardLayerFieldLabel } from '@/lib/disaster-terms';

type Layer = { id: number; name: string };

type Settings = {
    floodplain_layer_id: number | null;
    fault_layer_id: number | null;
    wildfire_layer_id: number | null;
    demographic_layer_id: number | null;
    ew_risk_layer_id: number | null;
    avl_max_skew_seconds: number;
    retention_avl_days: number;
    is_official_authority?: boolean;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    settings: Settings;
    layers: Layer[];
    update_url: string;
    can_manage: boolean;
};

export default function Hazards({
    advisory,
    authority = null,
    settings,
    layers,
    update_url,
    can_manage,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        floodplain_layer_id: settings.floodplain_layer_id ?? '',
        fault_layer_id: settings.fault_layer_id ?? '',
        wildfire_layer_id: settings.wildfire_layer_id ?? '',
        demographic_layer_id: settings.demographic_layer_id ?? '',
        ew_risk_layer_id: settings.ew_risk_layer_id ?? '',
        avl_max_skew_seconds: settings.avl_max_skew_seconds ?? 300,
        retention_avl_days: settings.retention_avl_days ?? 14,
        is_official_authority: Boolean(settings.is_official_authority),
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            floodplain_layer_id: data.floodplain_layer_id === '' ? null : Number(data.floodplain_layer_id),
            fault_layer_id: data.fault_layer_id === '' ? null : Number(data.fault_layer_id),
            wildfire_layer_id: data.wildfire_layer_id === '' ? null : Number(data.wildfire_layer_id),
            demographic_layer_id: data.demographic_layer_id === '' ? null : Number(data.demographic_layer_id),
            ew_risk_layer_id: data.ew_risk_layer_id === '' ? null : Number(data.ew_risk_layer_id),
        }));
        form.put(update_url, { preserveScroll: true });
    }

    return (
        <AppLayout
            title={t('disaster.hazards_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.hazards_short') }]}
        >
            <PageHeader title={t('disaster.hazards')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <Panel className="max-w-xl">
                <form onSubmit={submit} className="space-y-3 p-3">
                    {HAZARD_LAYER_SETTING_KEYS.map((key) => (
                        <Field key={key} label={hazardLayerFieldLabel(t, key)} error={form.errors[key]}>
                            <Select
                                value={String(form.data[key] ?? '')}
                                disabled={!can_manage}
                                onChange={(e) => form.setData(key, e.target.value)}
                            >
                                <option value="">{t('common.none')}</option>
                                {layers.map((layer) => (
                                    <option key={layer.id} value={layer.id}>
                                        {layer.name} (#{layer.id})
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ))}
                    <Field label={t('disaster.avl_max_skew')} error={form.errors.avl_max_skew_seconds}>
                        <TextInput
                            type="number"
                            min={0}
                            disabled={!can_manage}
                            value={String(form.data.avl_max_skew_seconds)}
                            onChange={(e) => form.setData('avl_max_skew_seconds', Number(e.target.value))}
                        />
                    </Field>
                    <Field label={t('disaster.avl_retention')} error={form.errors.retention_avl_days}>
                        <TextInput
                            type="number"
                            min={1}
                            disabled={!can_manage}
                            value={String(form.data.retention_avl_days)}
                            onChange={(e) => form.setData('retention_avl_days', Number(e.target.value))}
                        />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            disabled={!can_manage}
                            checked={form.data.is_official_authority}
                            onChange={(e) => form.setData('is_official_authority', e.target.checked)}
                        />
                        {t('disaster.is_official_authority')}
                    </label>
                    {can_manage ? (
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {t('disaster.save_settings')}
                        </PrimaryButton>
                    ) : (
                        <p className="text-sm text-muted-foreground">{t('disaster.hazards_manage_required')}</p>
                    )}
                </form>
            </Panel>
        </AppLayout>
    );
}
