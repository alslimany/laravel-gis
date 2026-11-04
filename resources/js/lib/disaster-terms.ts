import type { useTranslation } from '@/lib/i18n';

export type TFunction = ReturnType<typeof useTranslation>['t'];

export function termLabel(t: TFunction, prefix: string, value: string): string {
    const key = `${prefix}.${String(value).toLowerCase().replace(/\s+/g, '_')}`;
    const translated = t(key);

    return translated === key ? String(value) : translated;
}

export const disasterTermPrefix = {
    damageLevel: 'disaster.damage_level',
    closureType: 'disaster.closure_type',
    severity: 'disaster.severity',
    incidentStatus: 'disaster.incident_status',
    hazardKey: 'disaster.hazard_key',
    siteType: 'disaster.site_type',
    generatorStatus: 'disaster.generator_status',
    resourceStatus: 'disaster.resource_status',
    assetType: 'disaster.asset_type',
    conditionGrade: 'disaster.condition_grade',
} as const;

export type HazardLayerSettingKey =
    | 'floodplain_layer_id'
    | 'fault_layer_id'
    | 'wildfire_layer_id'
    | 'demographic_layer_id'
    | 'ew_risk_layer_id';

const LAYER_SETTING_LABEL_KEYS: Record<HazardLayerSettingKey, string> = {
    floodplain_layer_id: 'disaster.layer.floodplain',
    fault_layer_id: 'disaster.layer.fault',
    wildfire_layer_id: 'disaster.layer.wildfire',
    demographic_layer_id: 'disaster.layer.demographic',
    ew_risk_layer_id: 'disaster.layer.ew_risk',
};

export const HAZARD_LAYER_SETTING_KEYS: HazardLayerSettingKey[] = [
    'floodplain_layer_id',
    'fault_layer_id',
    'wildfire_layer_id',
    'demographic_layer_id',
    'ew_risk_layer_id',
];

export function hazardLayerFieldLabel(t: TFunction, key: HazardLayerSettingKey): string {
    return t(LAYER_SETTING_LABEL_KEYS[key]);
}
