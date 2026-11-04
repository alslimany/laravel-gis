import { defaults as defaultControls } from 'ol/control';
import type { DefaultsOptions } from 'ol/control/defaults';
import { __ } from '@/lib/i18n';
// Imported here so every map built with localizedControls() gets the RTL placement.
import './ol-rtl.css';

/** OpenLayers default controls with tooltips in the current UI language. */
export function localizedControls(options: DefaultsOptions = {}) {
    return defaultControls({
        ...options,
        zoomOptions: {
            zoomInTipLabel: __('maps.ctl.zoom_in'),
            zoomOutTipLabel: __('maps.ctl.zoom_out'),
            ...(options.zoomOptions ?? {}),
        },
        rotateOptions: { tipLabel: __('maps.ctl.reset_north'), ...(options.rotateOptions ?? {}) },
        attributionOptions: { tipLabel: __('maps.ctl.attributions'), ...(options.attributionOptions ?? {}) },
    });
}

export function fullScreenTip(): string {
    return __('maps.ctl.fullscreen');
}
