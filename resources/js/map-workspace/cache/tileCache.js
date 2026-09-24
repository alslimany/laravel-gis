const DB_NAME = 'gis-map-tiles';
const STORE = 'tiles';
const MONTH_MS = 30 * 24 * 60 * 60 * 1000;

function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'id' });
                store.createIndex('mapType', 'mapType');
                store.createIndex('cachedAt', 'cachedAt');
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function tileId(mapType, url) {
    return `${mapType}\n${url}`;
}

function requestResult(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function withStore(mode, run) {
    const db = await openDb();
    try {
        const tx = db.transaction(STORE, mode);
        const done = new Promise((resolve, reject) => {
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('Tile cache transaction aborted'));
        });
        const result = await run(tx.objectStore(STORE));
        await done;
        return result;
    } finally {
        db.close();
    }
}

export function cacheAgeLimit() {
    return MONTH_MS;
}

export async function readCachedTile(mapType, url) {
    const id = tileId(mapType, url);
    const record = await withStore('readwrite', (store) => {
        return new Promise((resolve, reject) => {
            const request = store.get(id);
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    });

    if (!record) {
        return null;
    }

    if (Date.now() - record.cachedAt > MONTH_MS) {
        await withStore('readwrite', (store) => store.delete(id));
        return null;
    }

    return record.blob;
}

export async function writeCachedTile(mapType, url, blob) {
    if (!blob || blob.size === 0) {
        return;
    }

    await withStore('readwrite', (store) =>
        store.put({
            id: tileId(mapType, url),
            mapType,
            url,
            blob,
            cachedAt: Date.now(),
        }),
    );
}

export function clearTileCache() {
    return withStore('readwrite', (store) => store.clear());
}

export function tileCacheCount() {
    return withStore('readonly', (store) => {
        return new Promise((resolve, reject) => {
            const request = store.count();
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    });
}

function showTile(image, blob) {
    const objectUrl = URL.createObjectURL(blob);
    const release = () => URL.revokeObjectURL(objectUrl);
    image.addEventListener('load', release, { once: true });
    image.addEventListener('error', release, { once: true });
    image.src = objectUrl;
}

/**
 * Intercept an OpenLayers tile image source. Cached tiles are reused for one month.
 * A failed cache read or a cross-origin fetch falls back to the original image URL.
 */
export function attachTileCache(source, mapType) {
    if (!source || source.get('tileCache') === mapType) {
        return source;
    }

    source.set('tileCache', mapType);
    source.setTileLoadFunction(async (tile, src) => {
        const image = tile.getImage();
        if (!(image instanceof HTMLImageElement)) {
            return;
        }

        try {
            const cached = await readCachedTile(mapType, src);
            if (cached) {
                showTile(image, cached);
                return;
            }
        } catch (error) {
            console.warn('Tile cache read failed', error);
        }

        try {
            const response = await fetch(src);
            if (!response.ok) {
                image.src = src;
                return;
            }
            const blob = await response.blob();
            if (!blob.type.startsWith('image/')) {
                image.src = src;
                return;
            }
            await writeCachedTile(mapType, src, blob);
            showTile(image, blob);
        } catch (error) {
            image.src = src;
        }
    });

    return source;
}
