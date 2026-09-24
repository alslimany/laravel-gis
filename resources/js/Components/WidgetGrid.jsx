import WidgetBoard from '../dashboards/WidgetBoard';

/** @deprecated Prefer WidgetBoard; kept for any remaining imports. */
export default function WidgetGrid({ widgets = [] }) {
    return <WidgetBoard widgets={widgets} widgetData={widgets} />;
}
