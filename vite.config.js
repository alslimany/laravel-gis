import path from 'path';
import { fileURLToPath } from 'url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import postcss from 'postcss';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function inKeyframes(rule) {
    let parent = rule.parent;
    while (parent) {
        if (parent.type === 'atrule' && parent.name === 'keyframes') {
            return true;
        }
        parent = parent.parent;
    }
    return false;
}

function scopeBootstrapToMapBuilder() {
    // Keep Bootstrap for legacy float panels / feature dock only.
    // Chrome (topbar, dock, status) must stay on shadcn tokens — Bootstrap
    // otherwise overrides `a` color and utilities like `.bg-secondary`.
    const SCOPE =
        ':is(.map-builder .map-float-panel, .map-builder .map-float-panel-body, .map-builder .data-dock)';

    return {
        name: 'scope-bootstrap-to-map-builder',
        enforce: 'pre',
        transform(code, id) {
            if (!id.includes('node_modules/bootstrap/dist/css/bootstrap')) {
                return null;
            }

            const root = postcss.parse(code);
            root.walkRules((rule) => {
                if (inKeyframes(rule) || !rule.selectors) {
                    return;
                }
                rule.selectors = rule.selectors.map((selector) => {
                    const trimmed = selector.trim();
                    if (trimmed.startsWith(':is(.map-builder .map-float-panel')) {
                        return selector;
                    }
                    if (trimmed.startsWith('.map-builder .map-float-panel') || trimmed.startsWith('.map-builder .map-drawer-body')) {
                        return selector;
                    }
                    if (trimmed === ':root' || trimmed === 'html' || trimmed === 'body') {
                        return SCOPE;
                    }
                    if (trimmed.startsWith('html ') || trimmed.startsWith('body ') || trimmed.startsWith(':root ')) {
                        return `${SCOPE} ${trimmed.replace(/^(html|body|:root)\s+/, '')}`;
                    }
                    // Legacy scope was `.map-builder ${sel}` — rewrite those too if reprocessed
                    if (trimmed.startsWith('.map-builder ')) {
                        return `${SCOPE} ${trimmed.slice('.map-builder '.length)}`;
                    }
                    if (trimmed === '.map-builder') {
                        return SCOPE;
                    }
                    return `${SCOPE} ${trimmed}`;
                });
            });

            return { code: root.toString(), map: null };
        },
    };
}

export default defineConfig({
    plugins: [
        scopeBootstrapToMapBuilder(),
        react(),
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                'resources/js/map-workspace/main.jsx',
                'resources/js/dashboards/main.jsx',
            ],
            refresh: true,
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});
