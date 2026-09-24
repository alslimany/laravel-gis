import '../bootstrap';
import { createRoot } from 'react-dom/client';
import App from './App';
import './console.css';

const el = document.getElementById('console-root');

if (el) {
    const raw = el.dataset.props || '{}';
    let props = {};
    try {
        props = JSON.parse(raw);
    } catch (error) {
        console.error('Failed to parse console props', error);
    }

    createRoot(el).render(<App {...props} />);
}
