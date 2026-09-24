import { useEffect, useRef, useState } from 'react';
import { Loader2, MapPin, Search, X } from 'lucide-react';
import { fromLonLat } from 'ol/proj';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useMapStore } from '../store/mapStore';

/**
 * Geocode search box. GETs /api/geocode?q=... and flies the map to the result.
 * Also listens for / dispatches `gis:geocode-fly` for parent integration.
 */
export default function GeocodeSearch({ placeholder = 'Search place…' }) {
    const map = useMapStore((state) => state.map);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [open, setOpen] = useState(false);
    const debounceRef = useRef(null);
    const wrapperRef = useRef(null);

    const flyTo = ({ lon, lat, display_name: displayName, zoom = 14 }) => {
        const detail = { lon: Number(lon), lat: Number(lat), display_name: displayName };

        window.dispatchEvent(new CustomEvent('gis:geocode-fly', { detail }));

        if (map) {
            const view = map.getView();
            view.animate({
                center: fromLonLat([detail.lon, detail.lat]),
                zoom,
                duration: 500,
            });
        }
    };

    useEffect(() => {
        const onFly = (event) => {
            const { lon, lat, display_name: displayName, zoom } = event.detail || {};
            if (lon == null || lat == null || !map) {
                return;
            }
            map.getView().animate({
                center: fromLonLat([Number(lon), Number(lat)]),
                zoom: zoom ?? 14,
                duration: 500,
            });
            if (displayName) {
                setQuery(displayName);
            }
            setOpen(false);
        };

        window.addEventListener('gis:geocode-fly', onFly);
        return () => window.removeEventListener('gis:geocode-fly', onFly);
    }, [map]);

    useEffect(() => {
        const onDocClick = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    useEffect(() => {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        const trimmed = query.trim();
        if (trimmed.length < 2) {
            setResults([]);
            setError(null);
            return undefined;
        }

        debounceRef.current = setTimeout(async () => {
            setLoading(true);
            setError(null);
            try {
                const response = await fetch(`/api/geocode?q=${encodeURIComponent(trimmed)}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Geocode failed (${response.status})`);
                }

                const data = await response.json();
                const list = Array.isArray(data)
                    ? data
                    : data.results || data.features || data.data || [];

                const normalized = list
                    .map((item) => {
                        if (item.geometry?.coordinates) {
                            const [lon, lat] = item.geometry.coordinates;
                            return {
                                lon,
                                lat,
                                display_name:
                                    item.properties?.display_name ||
                                    item.properties?.name ||
                                    item.display_name ||
                                    `${lat}, ${lon}`,
                            };
                        }

                        return {
                            lon: item.lon ?? item.lng ?? item.longitude,
                            lat: item.lat ?? item.latitude,
                            display_name:
                                item.display_name || item.name || item.label || `${item.lat}, ${item.lon}`,
                        };
                    })
                    .filter((item) => item.lon != null && item.lat != null);

                setResults(normalized);
                setOpen(true);
            } catch (err) {
                console.warn('Geocode search error:', err);
                setError(err.message || 'Search failed');
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 350);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, [query]);

    const selectResult = (item) => {
        setQuery(item.display_name);
        setOpen(false);
        flyTo(item);
    };

    return (
        <div className="geocode-search relative w-full max-w-sm" ref={wrapperRef}>
            <div className="relative flex w-full items-center gap-2">
                <div className="relative min-w-0 flex-1">
                    <span className="pointer-events-none absolute top-1/2 left-2.5 z-10 -translate-y-1/2 text-muted-foreground">
                        {loading ? (
                            <Loader2 className="size-3.5 animate-spin" strokeWidth={1.75} aria-hidden />
                        ) : (
                            <Search className="size-3.5" strokeWidth={1.75} aria-hidden />
                        )}
                    </span>
                    <Input
                        type="search"
                        placeholder={placeholder}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onFocus={() => results.length && setOpen(true)}
                        aria-label="Geocode search"
                        autoComplete="off"
                        className="h-9 bg-background pr-9 pl-8 shadow-xs"
                    />
                    {query ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="absolute top-1/2 right-1 size-7 -translate-y-1/2"
                            onClick={() => {
                                setQuery('');
                                setResults([]);
                                setOpen(false);
                            }}
                            aria-label="Clear search"
                        >
                            <X className="size-3.5" strokeWidth={1.75} />
                        </Button>
                    ) : null}
                </div>
            </div>

            {error ? <p className="mt-1 text-xs text-destructive">{error}</p> : null}

            {open && results.length > 0 ? (
                <Card className="absolute z-50 mt-1 w-full gap-0 overflow-hidden py-0 shadow-md">
                    <CardContent className="max-h-64 space-y-0.5 overflow-auto p-1">
                        {results.map((item, index) => (
                            <Button
                                key={`${item.lon}-${item.lat}-${index}`}
                                type="button"
                                variant="ghost"
                                className="h-auto w-full justify-start gap-2 px-2.5 py-2 text-left whitespace-normal"
                                onClick={() => selectResult(item)}
                            >
                                <MapPin
                                    className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                    strokeWidth={1.75}
                                    aria-hidden
                                />
                                <span className="text-sm leading-snug">{item.display_name}</span>
                            </Button>
                        ))}
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
