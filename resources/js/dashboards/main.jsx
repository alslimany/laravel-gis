import { createRoot } from 'react-dom/client';
import DashboardBoard from './DashboardBoard';
import '../console/console.css';

const el = document.getElementById('dashboard-board-root');
if (el) {
    const dashboardId = Number(el.dataset.dashboardId);
    const dataUrl = el.dataset.dataUrl;
    createRoot(el).render(<DashboardBoard dashboardId={dashboardId} dataUrl={dataUrl} />);
}
