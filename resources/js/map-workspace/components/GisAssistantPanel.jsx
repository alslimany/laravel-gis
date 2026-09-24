import { useState } from 'react';
import { Send, X } from 'lucide-react';
import { jsonHeaders } from '../utils/csrf';

export default function GisAssistantPanel({ onClose }) {
    const [message, setMessage] = useState('');
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const send = async (event) => {
        event.preventDefault();
        const text = message.trim();
        if (!text || loading) {
            return;
        }

        setLoading(true);
        setError(null);
        const nextHistory = [...history, { role: 'user', content: text }];
        setHistory(nextHistory);
        setMessage('');

        try {
            const response = await fetch('/ai/gis-assistant', {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    message: text,
                    history: history.map((item) => ({
                        role: item.role,
                        content: item.content,
                    })),
                }),
            });
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.message || data.error || 'Assistant failed');
            }
            setHistory((prev) => [
                ...prev,
                { role: 'assistant', content: data.reply || '' },
            ]);
        } catch (err) {
            setError(err.message || 'Assistant failed');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="analysis-panel gis-assistant-panel">
            <div className="panel-header">
                <h4>GIS Assistant</h4>
                <button type="button" onClick={onClose} className="close-btn" aria-label="Close">
                    <X className="glyph" strokeWidth={1.75} aria-hidden="true" />
                </button>
            </div>
            <div className="panel-body">
                <div className="assistant-thread mb-3">
                    {history.length === 0 && (
                        <p className="text-muted small mb-0">
                            Ask about layers, features, or buffer analysis in this organization.
                        </p>
                    )}
                    {history.map((item, index) => (
                        <div
                            key={`${item.role}-${index}`}
                            className={`assistant-turn assistant-turn-${item.role} mb-2`}
                        >
                            <strong className="d-block small text-muted">
                                {item.role === 'user' ? 'You' : 'Assistant'}
                            </strong>
                            <div>{item.content}</div>
                        </div>
                    ))}
                </div>
                {error && <div className="alert alert-danger py-2">{error}</div>}
                <form onSubmit={send} className="d-flex gap-2">
                    <input
                        className="form-control form-control-sm"
                        value={message}
                        onChange={(event) => setMessage(event.target.value)}
                        placeholder="Ask the GIS assistant…"
                        disabled={loading}
                    />
                    <button
                        type="submit"
                        className="btn btn-sm btn-primary"
                        disabled={loading || !message.trim()}
                    >
                        <Send className="glyph" strokeWidth={1.75} aria-hidden="true" />
                    </button>
                </form>
            </div>
        </div>
    );
}
