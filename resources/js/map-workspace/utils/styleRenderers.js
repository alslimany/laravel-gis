import { Style, Stroke, Fill, Circle as CircleStyle, Icon, Text } from 'ol/style';
import Point from 'ol/geom/Point';
import { mapIconUrl } from '../icons/pointIcons';

/** Built-in color ramps for classification. */
export const COLOR_RAMPS = {
    blues: ['#f7fbff', '#c6dbef', '#6baed6', '#2171b5', '#08306b'],
    greens: ['#f7fcf5', '#c7e9c0', '#74c476', '#238b45', '#00441b'],
    reds: ['#fff5f0', '#fcbba1', '#fb6a4a', '#cb181d', '#67000d'],
    oranges: ['#fff5eb', '#fdd0a2', '#fd8d3c', '#d94801', '#7f2704'],
    purples: ['#fcfbfd', '#dadaeb', '#9e9ac8', '#6a51a3', '#3f007d'],
    spectral: ['#d7191c', '#fdae61', '#ffffbf', '#abdda4', '#2b83ba'],
    viridis: ['#440154', '#3b528b', '#21918c', '#5ec962', '#fde725'],
};

const DEFAULT_SYMBOL = {
    fillColor: '#3388ff',
    fillOpacity: 0.6,
    strokeColor: '#3388ff',
    strokeWidth: 2,
    strokeOpacity: 1,
    pointRadius: 6,
};

/**
 * Normalize style from layer.style or layer.style_config into a common shape.
 */
export function normalizeStyleConfig(layerOrStyle) {
    if (!layerOrStyle) {
        return {
            renderer: 'simple',
            symbol: { ...DEFAULT_SYMBOL },
            labels: { enabled: false, field: null },
            legend: [],
        };
    }

    const raw =
        layerOrStyle.style_config ||
        layerOrStyle.style ||
        (layerOrStyle.renderer || layerOrStyle.symbol ? layerOrStyle : null) ||
        {};

    const symbol = {
        ...DEFAULT_SYMBOL,
        ...(raw.symbol || {}),
        fillColor: raw.symbol?.fillColor || raw.fillColor || raw.fill_color || DEFAULT_SYMBOL.fillColor,
        fillOpacity:
            raw.symbol?.fillOpacity ??
            raw.fillOpacity ??
            raw.fill_opacity ??
            DEFAULT_SYMBOL.fillOpacity,
        strokeColor:
            raw.symbol?.strokeColor ||
            raw.strokeColor ||
            raw.stroke_color ||
            DEFAULT_SYMBOL.strokeColor,
        strokeWidth:
            raw.symbol?.strokeWidth ??
            raw.strokeWidth ??
            raw.stroke_width ??
            DEFAULT_SYMBOL.strokeWidth,
        strokeOpacity:
            raw.symbol?.strokeOpacity ??
            raw.strokeOpacity ??
            raw.stroke_opacity ??
            DEFAULT_SYMBOL.strokeOpacity,
        pointRadius:
            raw.symbol?.pointRadius ??
            raw.pointRadius ??
            raw.point_radius ??
            DEFAULT_SYMBOL.pointRadius,
        icon: raw.symbol?.icon || null,
        size: raw.symbol?.size ?? 24,
    };

    return {
        renderer: raw.renderer || 'simple',
        field: raw.field || raw.classificationField || null,
        classes: raw.classes || raw.classBreaks || [],
        uniqueValues: raw.uniqueValues || raw.unique_values || [],
        colorRamp: raw.colorRamp || raw.color_ramp || 'blues',
        classCount: raw.classCount || raw.class_count || 5,
        heatmap: {
            blur: raw.heatmap?.blur ?? 15,
            radius: raw.heatmap?.radius ?? 8,
            weight: raw.heatmap?.weight || raw.field || null,
            gradient: raw.heatmap?.gradient || COLOR_RAMPS.spectral,
        },
        symbol,
        labels: {
            enabled: Boolean(raw.labels?.enabled ?? raw.labelsEnabled),
            field: raw.labels?.field || raw.labelField || null,
            color: raw.labels?.color || '#222222',
            font: raw.labels?.font || '12px sans-serif',
            offsetY: raw.labels?.offsetY ?? 12,
        },
        legend: Array.isArray(raw.legend) ? raw.legend : [],
    };
}

function hexToRgba(hex, opacity = 1) {
    if (!hex || typeof hex !== 'string') {
        return `rgba(51, 136, 255, ${opacity})`;
    }

    if (hex.startsWith('rgba') || hex.startsWith('rgb')) {
        return hex;
    }

    let value = hex.replace('#', '');
    if (value.length === 3) {
        value = value
            .split('')
            .map((c) => c + c)
            .join('');
    }

    const r = parseInt(value.slice(0, 2), 16);
    const g = parseInt(value.slice(2, 4), 16);
    const b = parseInt(value.slice(4, 6), 16);

    if (Number.isNaN(r) || Number.isNaN(g) || Number.isNaN(b)) {
        return `rgba(51, 136, 255, ${opacity})`;
    }

    return `rgba(${r}, ${g}, ${b}, ${opacity})`;
}

function buildSymbolStyle(symbol, labelStyle = null) {
    const fill = new Fill({
        color: hexToRgba(symbol.fillColor, symbol.fillOpacity ?? 0.6),
    });
    const stroke = new Stroke({
        color: hexToRgba(symbol.strokeColor, symbol.strokeOpacity ?? 1),
        width: symbol.strokeWidth ?? 2,
    });

    const iconName = symbol.icon && symbol.icon !== 'circle' ? symbol.icon : null;
    const image = iconName
        ? new Icon({
              src: mapIconUrl(iconName, symbol.fillColor || '#3388ff'),
              width: symbol.size ?? 24,
              height: symbol.size ?? 24,
              anchor: [0.5, 0.5],
          })
        : new CircleStyle({
              radius: symbol.pointRadius ?? 6,
              fill,
              stroke,
          });

    const main = new Style({
        fill,
        stroke,
        image,
        text: labelStyle || undefined,
    });

    // Optional casing (e.g. road closures: white halo under a dark line) for contrast on any basemap.
    if (symbol.casingWidth) {
        const casing = new Style({
            stroke: new Stroke({
                color: hexToRgba(symbol.casingColor || '#ffffff', 1),
                width: symbol.casingWidth,
            }),
        });

        return [casing, main];
    }

    return main;
}

/** Pixel length and midpoint of a (multi)line at this resolution; works for Feature and MVT RenderFeature. */
export function linePixelInfo(feature, resolution) {
    const geometry = feature?.getGeometry?.();
    if (!geometry || !resolution) return null;
    const type = geometry.getType();
    if (type !== 'LineString' && type !== 'MultiLineString') return null;
    const flat = geometry.getFlatCoordinates();
    const stride = geometry.getStride ? geometry.getStride() : 2;
    if (!flat || flat.length < stride * 2) return null;
    let length = 0;
    for (let i = stride; i < flat.length; i += stride) {
        length += Math.hypot(flat[i] - flat[i - stride], flat[i + 1] - flat[i - stride + 1]);
    }
    const mid = Math.floor(flat.length / stride / 2) * stride;
    return { px: length / resolution, mid: [flat[mid], flat[mid + 1]] };
}

const NO_ENTRY_SVG = (color) =>
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(
        `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><circle cx="9" cy="9" r="8" fill="${color}" stroke="#ffffff" stroke-width="2"/><rect x="4" y="7.5" width="10" height="3" rx="1" fill="#ffffff"/></svg>`,
    );

/**
 * Line layers with `minLengthPx` (road closures): thicker strokes when zoomed out
 * (`lowZoomResolution` / `lowZoomExtraWidth`), and a segment shorter than `minLengthPx` on screen
 * gets a "no entry" marker at its middle so it never reads as a stray dot.
 */
function withLowZoomEmphasis(styles, symbol, feature, resolution) {
    if (!symbol.minLengthPx && !symbol.lowZoomResolution) return styles;
    const list = Array.isArray(styles) ? styles : [styles];
    const zoomedOut = symbol.lowZoomResolution && resolution > symbol.lowZoomResolution;
    if (zoomedOut) {
        const extra = symbol.lowZoomExtraWidth ?? 2;
        list.forEach((style) => {
            const stroke = style.getStroke?.();
            if (stroke) stroke.setWidth((stroke.getWidth() ?? 1) + extra);
        });
    }
    const info = symbol.minLengthPx ? linePixelInfo(feature, resolution) : null;
    if (info && info.px < symbol.minLengthPx) {
        list.push(
            new Style({
                geometry: new Point(info.mid),
                image: new Icon({ src: NO_ENTRY_SVG(symbol.strokeColor || '#991b1b'), width: 18, height: 18 }),
                zIndex: 10,
            }),
        );
    }
    return list;
}

function buildLabelStyle(labels, feature) {
    if (!labels?.enabled || !labels.field) {
        return null;
    }

    const value = feature.get(labels.field);
    if (value === undefined || value === null || value === '') {
        return null;
    }

    return new Text({
        text: String(value),
        font: labels.font || '12px sans-serif',
        fill: new Fill({ color: labels.color || '#222222' }),
        stroke: new Stroke({ color: '#ffffff', width: 3 }),
        offsetY: labels.offsetY ?? 12,
        overflow: true,
    });
}

function getRampColors(rampName, count) {
    const ramp = COLOR_RAMPS[rampName] || COLOR_RAMPS.blues;
    if (count <= 1) {
        return [ramp[ramp.length - 1]];
    }

    if (count <= ramp.length) {
        const step = (ramp.length - 1) / (count - 1);
        return Array.from({ length: count }, (_, i) => ramp[Math.round(i * step)]);
    }

    // Interpolate by repeating end colors when more classes than stops.
    const colors = [];
    for (let i = 0; i < count; i += 1) {
        const t = i / (count - 1);
        const idx = t * (ramp.length - 1);
        const lo = Math.floor(idx);
        const hi = Math.min(lo + 1, ramp.length - 1);
        colors.push(ramp[lo] || ramp[hi]);
    }
    return colors;
}

/**
 * Resolve a fill color for a feature given a normalized style_config.
 */
export function resolveFeatureColor(config, feature) {
    const renderer = config.renderer || 'simple';
    const field = config.field;

    if (renderer === 'unique' && field) {
        const value = feature.get(field);
        const match = (config.uniqueValues || []).find(
            (entry) => String(entry.value) === String(value)
        );
        if (match?.color) {
            return match.color;
        }

        // Deterministic fallback from string hash when no explicit mapping.
        const ramp = COLOR_RAMPS[config.colorRamp] || COLOR_RAMPS.blues;
        const hash = String(value ?? '')
            .split('')
            .reduce((acc, ch) => acc + ch.charCodeAt(0), 0);
        return ramp[hash % ramp.length];
    }

    if (renderer === 'graduated' && field) {
        const numeric = Number(feature.get(field));
        if (!Number.isNaN(numeric) && Array.isArray(config.classes) && config.classes.length) {
            for (const cls of config.classes) {
                const min = cls.min ?? cls.from;
                const max = cls.max ?? cls.to;
                if (
                    (min === undefined || numeric >= min) &&
                    (max === undefined || numeric <= max)
                ) {
                    return cls.color || config.symbol.fillColor;
                }
            }
            return config.classes[config.classes.length - 1]?.color || config.symbol.fillColor;
        }
    }

    return config.symbol.fillColor;
}

/**
 * Build an OpenLayers style function from style_config.
 * Heatmap renderer returns null — caller should use Heatmap layer instead.
 */
export function createStyleFunction(styleConfigOrLayer) {
    const config = normalizeStyleConfig(styleConfigOrLayer);

    if (config.renderer === 'heatmap') {
        return null;
    }

    return (feature, resolution) => {
        const color = resolveFeatureColor(config, feature);
        let icon = config.symbol.icon || null;
        if (config.renderer === 'unique' && config.field) {
            const value = feature.get(config.field);
            const match = (config.uniqueValues || []).find(
                (entry) => String(entry.value) === String(value)
            );
            if (match?.icon) {
                icon = match.icon;
            }
        }
        const symbol = {
            ...config.symbol,
            icon,
            fillColor: color,
            strokeColor:
                config.renderer === 'simple' ? config.symbol.strokeColor : color,
        };
        const labels = icon && icon !== 'circle'
            ? { ...config.labels, offsetY: (symbol.size ?? 24) / 2 + 8 }
            : config.labels;
        const label = buildLabelStyle(labels, feature);
        return withLowZoomEmphasis(buildSymbolStyle(symbol, label), symbol, feature, resolution);
    };
}

/**
 * Whether the layer should use an OL Heatmap layer.
 */
export function isHeatmapRenderer(styleConfigOrLayer) {
    return normalizeStyleConfig(styleConfigOrLayer).renderer === 'heatmap';
}

/**
 * Heatmap layer options derived from style_config.
 */
export function getHeatmapOptions(styleConfigOrLayer) {
    const config = normalizeStyleConfig(styleConfigOrLayer);
    return {
        blur: config.heatmap.blur,
        radius: config.heatmap.radius,
        weight: config.heatmap.weight
            ? (feature) => {
                  const raw = Number(feature.get(config.heatmap.weight));
                  return Number.isNaN(raw) ? 1 : Math.max(0, raw);
              }
            : () => 1,
        gradient: config.heatmap.gradient,
    };
}

/**
 * Build legend entries from style_config for Legend.jsx.
 */
export function buildLegendEntries(styleConfigOrLayer, layerName = '') {
    const config = normalizeStyleConfig(styleConfigOrLayer);

    if (Array.isArray(config.legend) && config.legend.length) {
        return config.legend.map((item) => ({
            label: item.label || item.name || String(item.value ?? ''),
            color: item.color || config.symbol.fillColor,
            icon: item.icon || config.symbol.icon || null,
            layerName,
        }));
    }

    if (config.renderer === 'unique' && config.uniqueValues?.length) {
        return config.uniqueValues.map((entry) => ({
            label: entry.label || String(entry.value),
            color: entry.color || config.symbol.fillColor,
            icon: entry.icon || config.symbol.icon || null,
            layerName,
        }));
    }

    if (config.renderer === 'graduated' && config.classes?.length) {
        return config.classes.map((cls, index) => {
            const min = cls.min ?? cls.from;
            const max = cls.max ?? cls.to;
            const label =
                cls.label ||
                (min !== undefined && max !== undefined
                    ? `${min} – ${max}`
                    : `Class ${index + 1}`);
            return {
                label,
                color: cls.color || config.symbol.fillColor,
                icon: config.symbol.icon || null,
                layerName,
            };
        });
    }

    if (config.renderer === 'heatmap') {
        const colors = config.heatmap.gradient || COLOR_RAMPS.spectral;
        return [
            { label: 'Low', color: colors[0], layerName },
            { label: 'High', color: colors[colors.length - 1], layerName },
        ];
    }

    return [
        {
            label: layerName || 'Layer',
            color: config.symbol.fillColor,
            icon: config.symbol.icon || null,
            layerName,
        },
    ];
}

/**
 * Auto-generate equal-interval class breaks for graduated renderer UI.
 */
export function buildEqualIntervalClasses(min, max, classCount, colorRamp = 'blues') {
    const count = Math.max(2, Number(classCount) || 5);
    const lo = Number(min);
    const hi = Number(max);
    if (Number.isNaN(lo) || Number.isNaN(hi) || hi <= lo) {
        return [];
    }

    const colors = getRampColors(colorRamp, count);
    const step = (hi - lo) / count;

    return Array.from({ length: count }, (_, i) => {
        const from = lo + step * i;
        const to = i === count - 1 ? hi : lo + step * (i + 1);
        return {
            min: Number(from.toFixed(4)),
            max: Number(to.toFixed(4)),
            color: colors[i],
            label: `${from.toFixed(2)} – ${to.toFixed(2)}`,
        };
    });
}

/**
 * Assign colors to unique values for the unique renderer UI.
 */
export function buildUniqueValueEntries(values, colorRamp = 'blues') {
    const unique = [...new Set(values.map((v) => String(v)))];
    const colors = getRampColors(colorRamp, Math.max(unique.length, 1));

    return unique.map((value, index) => ({
        value,
        label: value,
        color: colors[index % colors.length],
    }));
}
