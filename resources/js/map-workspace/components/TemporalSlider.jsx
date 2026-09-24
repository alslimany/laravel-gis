import { Clock } from 'lucide-react';
import { useMapStore } from '../store/mapStore';

/**
 * Temporal filter slider for MVT / vector layers that declare a time field.
 */
export default function TemporalSlider() {
    const layers = useMapStore((state) => state.layers);
    const updateLayer = useMapStore((state) => state.updateLayer);
    const selectedLayerId = useMapStore((state) => state.selectedLayer);
    const selected = layers.find((l) => l.id === selectedLayerId);

    const timeField =
        selected?.timeField ||
        selected?.style_config?.timeField ||
        selected?.metadata?.time_field;

    if (!selected || !timeField) {
        return null;
    }

    const min = selected.timeMin || '2020-01-01';
    const max = selected.timeMax || new Date().toISOString().slice(0, 10);
    const value = selected.timeTo || selected.timeFrom || max;

    return (
        <div className="temporal-slider">
            <label className="form-label mb-1">
                <Clock className="glyph" strokeWidth={1.75} aria-hidden="true" /> Time ({timeField})
            </label>
            <input
                type="date"
                className="form-control form-control-sm"
                min={min}
                max={max}
                value={value}
                onChange={(event) => {
                    updateLayer(selected.id, {
                        timeField,
                        timeFrom: event.target.value,
                        timeTo: event.target.value,
                    });
                }}
            />
        </div>
    );
}
