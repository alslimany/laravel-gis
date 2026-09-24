import { useMemo, useRef, useState } from 'react';
import { ChartColumn, ChevronDown, ChevronRight, Filter, Hash, List, Map, PieChart, Table2, Type } from 'lucide-react';
import { TextInput } from '@/components/gis';

const ICONS = {
    indicator: Hash,
    serial: ChartColumn,
    pie: PieChart,
    table: Table2,
    list: List,
    map: Map,
    text: Type,
    category: Filter,
};

const GROUP_ORDER = ['Data', 'Map', 'Content', 'Filters'];

export default function Library({ catalog = [], onAdd, onDragType }) {
    const [query, setQuery] = useState('');
    const [openGroups, setOpenGroups] = useState(() => Object.fromEntries(GROUP_ORDER.map((group) => [group, true])));
    const didDragRef = useRef(false);

    const groups = useMemo(() => {
        const needle = query.trim().toLowerCase();
        const filtered = catalog.filter((item) => {
            if (!needle) return true;
            return [item.label, item.description, item.group, item.type].filter(Boolean).join(' ').toLowerCase().includes(needle);
        });

        const next = {};
        filtered.forEach((item) => {
            const group = item.group || 'Data';
            if (!next[group]) next[group] = [];
            next[group].push(item);
        });
        return next;
    }, [catalog, query]);

    const orderedGroups = useMemo(() => {
        const keys = Object.keys(groups);
        return [
            ...GROUP_ORDER.filter((group) => keys.includes(group)),
            ...keys.filter((group) => !GROUP_ORDER.includes(group)),
        ];
    }, [groups]);

    function toggleGroup(group) {
        setOpenGroups((current) => ({ ...current, [group]: !current[group] }));
    }

    function startDrag(event, type) {
        didDragRef.current = true;
        event.dataTransfer.setData('application/x-dashboard-widget', type);
        event.dataTransfer.setData('text/plain', type);
        event.dataTransfer.effectAllowed = 'copy';
        onDragType?.(type);
    }

    function endDrag() {
        onDragType?.(null);
        window.setTimeout(() => {
            didDragRef.current = false;
        }, 0);
    }

    function addFromLibrary(type) {
        if (didDragRef.current) return;
        onAdd(type);
    }

    return (
        <aside className="flex w-64 shrink-0 flex-col border-r border-line bg-panel">
            <div className="border-b border-line px-4 py-3">
                <p className="font-semibold">Components</p>
                <TextInput type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search components" />
            </div>
            <div className="flex-1 space-y-2 overflow-y-auto px-3 py-3">
                {orderedGroups.length === 0 ? (
                    <p className="px-1 py-4 text-muted">No components match that search.</p>
                ) : (
                    orderedGroups.map((group) => {
                        const items = groups[group] || [];
                        const open = query.trim() ? true : Boolean(openGroups[group]);
                        return (
                            <div key={group}>
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(group)}
                                    className="flex w-full items-center gap-2 rounded-md px-2 text-left font-semibold text-copy hover:bg-panel-2"
                                    aria-expanded={open}
                                >
                                    {open ? <ChevronDown className="h-4 w-4 text-muted" /> : <ChevronRight className="h-4 w-4 text-muted" />}
                                    <span className="flex-1 truncate">{group}</span>
                                    <span className="font-medium text-muted">{items.length}</span>
                                </button>
                                {open ? (
                                    <div className="mt-1 space-y-2 pb-2">
                                        {items.map((item) => {
                                            const Icon = ICONS[item.type] || Hash;
                                            return (
                                                <div
                                                    key={item.type}
                                                    role="button"
                                                    tabIndex={0}
                                                    draggable
                                                    onDragStart={(event) => startDrag(event, item.type)}
                                                    onDragEnd={endDrag}
                                                    onClick={() => addFromLibrary(item.type)}
                                                    onKeyDown={(event) => {
                                                        if (event.key === 'Enter' || event.key === ' ') {
                                                            event.preventDefault();
                                                            addFromLibrary(item.type);
                                                        }
                                                    }}
                                                    className="flex w-full cursor-grab items-start gap-3 rounded-md border border-line bg-canvas px-3 py-3 text-left hover:border-cyan active:cursor-grabbing"
                                                >
                                                    <Icon className="mt-0.5 h-4 w-4 shrink-0 text-link" strokeWidth={1.75} />
                                                    <span>
                                                        <span className="block font-medium">{item.label}</span>
                                                        <span className="block text-muted">{item.description}</span>
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : null}
                            </div>
                        );
                    })
                )}
            </div>
        </aside>
    );
}
