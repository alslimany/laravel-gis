import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    ChartColumn,
    Check,
    CircleCheck,
    Clock,
    CloudDownload,
    CloudUpload,
    Code,
    Copy,
    Database,
    Download,
    Ellipsis,
    ExternalLink,
    Eye,
    EyeOff,
    FileJson,
    FileSpreadsheet,
    Filter,
    Folder,
    Globe,
    House,
    Image,
    Info,
    KeyRound,
    Layers,
    Link,
    List,
    Loader2,
    LogOut,
    Map,
    MapPin,
    Menu,
    Palette,
    Pencil,
    Plus,
    Printer,
    RefreshCw,
    Save,
    Search,
    Settings,
    Share2,
    Table,
    Trash2,
    TriangleAlert,
    Undo2,
    Upload,
    User,
    Users,
    X,
} from 'lucide-react';

const ICONS = {
    'fa-plus': Plus,
    'fa-eye': Eye,
    'fa-eye-slash': EyeOff,
    'fa-edit': Pencil,
    'fa-pen': Pencil,
    'fa-pencil-alt': Pencil,
    'fa-trash': Trash2,
    'fa-trash-alt': Trash2,
    'fa-arrow-left': ArrowLeft,
    'fa-arrow-right': ArrowRight,
    'fa-download': Download,
    'fa-search': Search,
    'fa-print': Printer,
    'fa-times': X,
    'fa-close': X,
    'fa-check': Check,
    'fa-spinner': Loader2,
    'fa-circle-notch': Loader2,
    'fa-layer-group': Layers,
    'fa-save': Save,
    'fa-chart-area': ChartColumn,
    'fa-chart-bar': ChartColumn,
    'fa-map': Map,
    'fa-map-marker-alt': MapPin,
    'fa-image': Image,
    'fa-file-csv': FileSpreadsheet,
    'fa-file': FileJson,
    'fa-palette': Palette,
    'fa-sync': RefreshCw,
    'fa-undo': Undo2,
    'fa-clock': Clock,
    'fa-cloud-upload-alt': CloudUpload,
    'fa-cloud-download-alt': CloudDownload,
    'fa-exclamation-triangle': TriangleAlert,
    'fa-check-circle': CircleCheck,
    'fa-code': Code,
    'fa-list': List,
    'fa-cog': Settings,
    'fa-users': Users,
    'fa-folder': Folder,
    'fa-upload': Upload,
    'fa-info-circle': Info,
    'fa-ellipsis-h': Ellipsis,
    'fa-share': Share2,
    'fa-share-alt': Share2,
    'fa-link': Link,
    'fa-copy': Copy,
    'fa-external-link-alt': ExternalLink,
    'fa-home': House,
    'fa-sign-out-alt': LogOut,
    'fa-bars': Menu,
    'fa-filter': Filter,
    'fa-table': Table,
    'fa-database': Database,
    'fa-globe': Globe,
    'fa-key': KeyRound,
    'fa-user': User,
    'fa-building': Building2,
};

export function hydrateBladeIcons(root) {
    if (!root) {
        return;
    }

    root.querySelectorAll('i').forEach((node) => {
        const name = [...node.classList].find((token) => ICONS[token]);
        if (!name) {
            return;
        }

        const big = node.classList.contains('fa-2x') || node.classList.contains('fa-3x') || node.classList.contains('fa-4x');
        const markup = renderToStaticMarkup(
            createElement(ICONS[name], {
                size: big ? 28 : 15,
                strokeWidth: 1.75,
                'aria-hidden': true,
                className: node.classList.contains('fa-spin') ? 'blade-icon spin' : 'blade-icon',
            }),
        );
        const wrap = document.createElement('span');
        wrap.className = 'blade-icon-wrap';
        wrap.innerHTML = markup;
        node.replaceWith(wrap);
    });
}
