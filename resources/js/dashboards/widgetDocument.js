export const DEFAULT_SIZE = {
    indicator: { w: 3, h: 2 },
    serial: { w: 6, h: 4 },
    pie: { w: 4, h: 4 },
    table: { w: 6, h: 5 },
    list: { w: 4, h: 5 },
    map: { w: 6, h: 5 },
    text: { w: 4, h: 2 },
    category: { w: 3, h: 2 },
};

const TITLES = {
    indicator: 'Indicator',
    serial: 'Chart',
    pie: 'Pie chart',
    table: 'Table',
    list: 'List',
    map: 'Map',
    text: 'Text',
    category: 'Filter',
};

export function canonicalType(type) {
    const value = String(type || 'indicator').toLowerCase();
    if (value === 'kpi') return 'indicator';
    if (value === 'bar' || value === 'line') return 'serial';
    return DEFAULT_SIZE[value] ? value : 'indicator';
}

export function createWidget(type, index = 0) {
    const resolved = canonicalType(type);
    const size = DEFAULT_SIZE[resolved];
    const id = `w_${resolved}_${Date.now().toString(36)}_${index}`;

    return {
        id,
        type: resolved,
        title: TITLES[resolved],
        layout: { x: 0, y: Infinity, w: size.w, h: size.h },
        aggregation: 'count',
        source: resolved === 'map' ? 'layer' : undefined,
        map_id: resolved === 'map' ? null : undefined,
        chart_style: resolved === 'serial' ? 'bar' : undefined,
        limit: resolved === 'table' || resolved === 'list' ? 20 : undefined,
        body: resolved === 'text' ? '' : undefined,
    };
}

export function normalizeWidgets(widgets = []) {
    let cursorX = 0;
    let cursorY = 0;
    let rowHeight = 0;

    return (Array.isArray(widgets) ? widgets : []).map((raw, index) => {
        const type = canonicalType(raw.type);
        const size = DEFAULT_SIZE[type];
        const hasLayout = raw.layout && typeof raw.layout === 'object';
        let layout = hasLayout
            ? {
                  x: Number(raw.layout.x) || 0,
                  y: Number(raw.layout.y) || 0,
                  w: Math.min(12, Math.max(1, Number(raw.layout.w) || size.w)),
                  h: Math.max(1, Number(raw.layout.h) || size.h),
              }
            : null;

        if (!layout) {
            if (cursorX + size.w > 12) {
                cursorX = 0;
                cursorY += Math.max(rowHeight, 1);
                rowHeight = 0;
            }
            layout = { x: cursorX, y: cursorY, w: size.w, h: size.h };
            cursorX += size.w;
            rowHeight = Math.max(rowHeight, size.h);
        }

        return {
            ...raw,
            id: raw.id || `w_${index}_${type}`,
            type,
            title: raw.title || TITLES[type],
            layout,
            aggregation: raw.aggregation || 'count',
            chart_style: raw.chart_style || (type === 'serial' ? (String(raw.type).toLowerCase() === 'line' ? 'line' : 'bar') : undefined),
        };
    });
}

export function toGridLayout(widgets) {
    return widgets.map((widget) => ({
        i: widget.id,
        x: widget.layout.x,
        y: widget.layout.y,
        w: widget.layout.w,
        h: widget.layout.h,
        minW: 2,
        minH: 2,
    }));
}

export function applyGridLayout(widgets, layout) {
    const byId = Object.fromEntries((layout || []).map((item) => [item.i, item]));
    return widgets.map((widget) => {
        const next = byId[widget.id];
        if (!next) return widget;
        return {
            ...widget,
            layout: { x: next.x, y: next.y, w: next.w, h: next.h },
        };
    });
}

export function fieldLabel(field) {
    if (!field) return '';
    return field.alias || field.name;
}
