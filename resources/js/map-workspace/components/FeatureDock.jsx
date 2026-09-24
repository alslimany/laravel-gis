import { useEffect, useState } from 'react';
import { jsonHeaders } from '../utils/csrf';

/**
 * Right-panel dock for create/edit feature attributes and identify + attachments.
 */
export default function FeatureDock({
    mode,
    layer,
    featureId,
    properties,
    onPropertiesChange,
    geometryWkt,
    onClose,
    onSaved,
}) {
    const [saving, setSaving] = useState(false);
    const [attachments, setAttachments] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [detail, setDetail] = useState(null);
    const [error, setError] = useState(null);

    const isIdentify = mode === 'identify';
    const isEdit = mode === 'edit';
    const isCreate = mode === 'create';

    useEffect(() => {
        if (!layer?.id || !featureId || (!isIdentify && !isEdit)) {
            setAttachments([]);
            setDetail(null);
            return;
        }

        let cancelled = false;
        (async () => {
            try {
                const [featureRes, attachRes] = await Promise.all([
                    fetch(`/api/layers/${layer.id}/features/${featureId}`, {
                        headers: jsonHeaders(),
                    }),
                    fetch(`/api/layers/${layer.id}/features/${featureId}/attachments`, {
                        headers: jsonHeaders(),
                    }),
                ]);
                const featureData = await featureRes.json();
                const attachData = await attachRes.json();
                if (cancelled) {
                    return;
                }
                if (featureRes.ok) {
                    const feature = featureData.data || featureData.feature || featureData;
                    setDetail(feature);
                    if (isEdit && feature?.properties && onPropertiesChange) {
                        onPropertiesChange({ ...feature.properties });
                    }
                }
                if (attachRes.ok) {
                    setAttachments(attachData.attachments || []);
                }
            } catch (err) {
                if (!cancelled) {
                    setError(err.message);
                }
            }
        })();

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [layer?.id, featureId, mode]);

    const save = async () => {
        if (!layer?.id) {
            setError('Select a layer before saving.');
            return;
        }
        if (isCreate && !geometryWkt) {
            setError('No geometry to save.');
            return;
        }

        setSaving(true);
        setError(null);
        try {
            const url =
                isEdit && featureId
                    ? `/api/layers/${layer.id}/features/${featureId}`
                    : `/api/layers/${layer.id}/features`;
            const method = isEdit ? 'PUT' : 'POST';
            const body = {
                attributes: { ...properties },
            };
            if (geometryWkt) {
                body.wkt = geometryWkt;
            }

            const response = await fetch(url, {
                method,
                headers: jsonHeaders(),
                body: JSON.stringify(body),
            });
            const data = await response.json();
            if (!response.ok || data.error) {
                throw new Error(data.error || 'Failed to save feature');
            }
            onSaved?.(data.feature || data.data || data);
        } catch (err) {
            setError(err.message || 'Failed to save feature');
        } finally {
            setSaving(false);
        }
    };

    const uploadAttachment = async (event) => {
        const file = event.target.files?.[0];
        if (!file || !layer?.id || !featureId) {
            return;
        }
        setUploading(true);
        setError(null);
        try {
            const form = new FormData();
            form.append('file', file);
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');
            const response = await fetch(
                `/api/layers/${layer.id}/features/${featureId}/attachments`,
                {
                    method: 'POST',
                    headers: token ? { 'X-CSRF-TOKEN': token } : {},
                    body: form,
                }
            );
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Upload failed');
            }
            setAttachments((prev) => [data.attachment, ...prev]);
            event.target.value = '';
        } catch (err) {
            setError(err.message);
        } finally {
            setUploading(false);
        }
    };

    const title = isIdentify
        ? 'Identify'
        : isEdit
          ? `Edit feature #${featureId}`
          : 'Feature properties';

    const displayProps = isIdentify
        ? detail?.properties || properties || {}
        : properties || {};

    return (
        <div className="data-dock" role="region" aria-label={title}>
            <div className="modal-header">
                <h5>{title}</h5>
                <button type="button" onClick={onClose} className="btn-close" aria-label="Close" />
            </div>
            <div className="modal-body">
                {!layer && (
                    <div className="alert alert-warning">
                        Select a target layer in the layer panel.
                    </div>
                )}
                {error && <div className="alert alert-danger">{error}</div>}

                {isIdentify ? (
                    <div className="mb-3">
                        <dl className="mb-0">
                            {Object.entries(displayProps).map(([key, value]) => (
                                <div key={key} className="mb-2">
                                    <dt className="text-muted small mb-0">{key}</dt>
                                    <dd className="mb-0">{String(value ?? '')}</dd>
                                </div>
                            ))}
                            {Object.keys(displayProps).length === 0 && (
                                <p className="text-muted mb-0">No attributes.</p>
                            )}
                        </dl>
                    </div>
                ) : (
                    Object.keys(displayProps).length > 0
                        ? Object.keys(displayProps).map((key) => (
                              <div className="mb-3" key={key}>
                                  <label className="form-label">{key}</label>
                                  <input
                                      value={displayProps[key] ?? ''}
                                      onChange={(event) =>
                                          onPropertiesChange?.({
                                              ...displayProps,
                                              [key]: event.target.value,
                                          })
                                      }
                                      type="text"
                                      className="form-control"
                                  />
                              </div>
                          ))
                        : ['name', 'description', 'type', 'notes'].map((key) => (
                              <div className="mb-3" key={key}>
                                  <label className="form-label">
                                      {key.charAt(0).toUpperCase() + key.slice(1)}
                                  </label>
                                  {key === 'description' || key === 'notes' ? (
                                      <textarea
                                          value={displayProps[key] ?? ''}
                                          onChange={(event) =>
                                              onPropertiesChange?.({
                                                  ...displayProps,
                                                  [key]: event.target.value,
                                              })
                                          }
                                          className="form-control"
                                          rows={key === 'description' ? 3 : 2}
                                      />
                                  ) : (
                                      <input
                                          value={displayProps[key] ?? ''}
                                          onChange={(event) =>
                                              onPropertiesChange?.({
                                                  ...displayProps,
                                                  [key]: event.target.value,
                                              })
                                          }
                                          type="text"
                                          className="form-control"
                                      />
                                  )}
                              </div>
                          ))
                )}

                {(isIdentify || isEdit) && featureId && layer?.id ? (
                    <div className="mb-3">
                        <h6 className="mb-2">Attachments</h6>
                        <ul className="list-unstyled mb-2">
                            {attachments.map((item) => (
                                <li key={item.id} className="mb-1">
                                    <a
                                        href={`/api/layers/${layer.id}/features/${featureId}/attachments/${item.id}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {item.file_name}
                                    </a>
                                </li>
                            ))}
                            {attachments.length === 0 && (
                                <li className="text-muted small">No attachments</li>
                            )}
                        </ul>
                        <input
                            type="file"
                            className="form-control form-control-sm"
                            onChange={uploadAttachment}
                            disabled={uploading}
                        />
                    </div>
                ) : null}
            </div>
            {!isIdentify ? (
                <div className="modal-footer">
                    <button type="button" onClick={onClose} className="btn btn-secondary">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={save}
                        className="btn btn-primary"
                        disabled={saving}
                    >
                        {saving ? 'Saving…' : isEdit ? 'Update feature' : 'Save feature'}
                    </button>
                </div>
            ) : null}
        </div>
    );
}
