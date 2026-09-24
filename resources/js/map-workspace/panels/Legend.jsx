import { useMemo } from 'react';
import { List } from 'lucide-react';
import { useMapStore } from '../store/mapStore';
import { mapIconUrl } from '../icons/pointIcons';
import { buildLegendEntries, normalizeStyleConfig } from '../utils/styleRenderers';

/**
 * Legend for visible (or selected) layers based on style_config.
 */
export default function Legend({ showSelectedOnly = false }) {
    const layers = useMapStore((state) => state.layers);
    const selectedLayerId = useMapStore((state) => state.selectedLayer);

    const entries = useMemo(() => {
        const source = showSelectedOnly
            ? layers.filter((layer) => layer.id === selectedLayerId)
            : layers.filter((layer) => layer.visible !== false);

        return source.flatMap((layer) => {
            const config = normalizeStyleConfig(layer);
            const items = buildLegendEntries(config, layer.name || layer.title || `Layer ${layer.id}`);
            return items.map((item, index) => ({
                ...item,
                key: `${layer.id}-${index}-${item.label}`,
                layerId: layer.id,
            }));
        });
    }, [layers, selectedLayerId, showSelectedOnly]);

    if (!entries.length) {
        return (
            <div className="map-legend card border-0 shadow-sm">
                <div className="card-body p-2">
                    <div className="small text-muted">No legend items</div>
                </div>
            </div>
        );
    }

    // Group by layer name for readability
    const byLayer = entries.reduce((acc, entry) => {
        const name = entry.layerName || 'Layer';
        if (!acc[name]) {
            acc[name] = [];
        }
        acc[name].push(entry);
        return acc;
    }, {});

    return (
        <div className="map-legend card border-0 shadow-sm">
            <div className="card-header py-1 px-2 bg-white">
                <strong className="small">
                    <List className="glyph" strokeWidth={1.75} aria-hidden="true" /> Legend
                </strong>
            </div>
            <div className="card-body p-2" style={{ maxHeight: 240, overflowY: 'auto' }}>
                {Object.entries(byLayer).map(([layerName, items]) => (
                    <div key={layerName} className="mb-2">
                        <div className="small fw-semibold text-muted mb-1">{layerName}</div>
                        <ul className="list-unstyled mb-0">
                            {items.map((item) => (
                                <li
                                    key={item.key}
                                    className="d-flex align-items-center gap-2 mb-1 small"
                                >
                                    {item.icon && item.icon !== 'circle' ? (
                                        <img
                                            src={mapIconUrl(item.icon, item.color)}
                                            alt=""
                                            width={16}
                                            height={16}
                                        />
                                    ) : (
                                        <span
                                            aria-hidden="true"
                                            style={{
                                                width: 14,
                                                height: 14,
                                                borderRadius: 2,
                                                backgroundColor: item.color,
                                                border: '1px solid rgba(0,0,0,0.25)',
                                                flexShrink: 0,
                                            }}
                                        />
                                    )}
                                    <span>{item.label}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}
