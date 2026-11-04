import { useEffect, useState } from 'react';
import { jsonHeaders } from '../utils/csrf';

import { __ } from '@/lib/i18n';
import { fieldLabel } from '@/lib/enum-labels';
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
    const [fieldDefs, setFieldDefs] = useState({});
    const [relationships, setRelationships] = useState([]);
    const [related, setRelated] = useState([]);

    const isIdentify = mode === 'identify';
    const isEdit = mode === 'edit';
    const isCreate = mode === 'create';

    useEffect(() => {
        if (!layer?.id) {
            setFieldDefs({});
            return undefined;
        }

        let cancelled = false;
        fetch(`/api/layers/${layer.id}/fields`, { headers: jsonHeaders() })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                if (cancelled) {
                    return;
                }
                const map = {};
                (data?.fields || []).forEach((field) => {
                    map[field.name] = field;
                });
                setFieldDefs(map);
            })
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [layer?.id]);

    function domainOptions(key) {
        const domain = fieldDefs[key]?.domain_values;
        if (!Array.isArray(domain)) {
            return [];
        }
        return domain
            .map((entry) => (entry && typeof entry === 'object' ? String(entry.value ?? '') : String(entry)))
            .filter((value) => value !== '');
    }

    useEffect(() => {
        if (!layer?.id) {
            setRelationships([]);
            return undefined;
        }

        let cancelled = false;
        fetch(`/api/layers/${layer.id}/relationships`, { headers: jsonHeaders() })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                if (!cancelled) {
                    setRelationships(data?.relationships || []);
                }
            })
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [layer?.id]);

    useEffect(() => {
        if (!layer?.id || !featureId || (!isIdentify && !isEdit) || relationships.length === 0) {
            setRelated([]);
            return undefined;
        }

        let cancelled = false;
        Promise.all(
            relationships.map(async (relationship) => {
                try {
                    const res = await fetch(
                        `/api/layers/${layer.id}/relationships/${relationship.id}/features?parent_id=${featureId}`,
                        { headers: jsonHeaders() },
                    );
                    if (!res.ok) {
                        return null;
                    }
                    const data = await res.json();
                    return {
                        relationship,
                        columns: data.columns || [],
                        features: data.features || [],
                    };
                } catch {
                    return null;
                }
            }),
        ).then((results) => {
            if (!cancelled) {
                setRelated(results.filter(Boolean));
            }
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [layer?.id, featureId, mode, relationships]);

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
            setError(__('dock.need_layer'));
            return;
        }
        if (isCreate && !geometryWkt) {
            setError(__('dock.no_geometry'));
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
                const details = data.errors ? Object.values(data.errors).join(' ') : null;
                throw new Error(details || data.error || __('dock.save_failed'));
            }
            onSaved?.(data.feature || data.data || data);
        } catch (err) {
            setError(err.message || __('dock.save_failed'));
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
                throw new Error(data.error || __('dock.upload_failed'));
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
        ? __('tools.identify')
        : isEdit
          ? __('dock.edit_feature', { id: featureId })
          : __('dock.properties');

    const displayProps = isIdentify
        ? detail?.properties || properties || {}
        : properties || {};

    return (
        <div className="data-dock" role="region" aria-label={title}>
            <div className="modal-header">
                <h5>{title}</h5>
                <button type="button" onClick={onClose} className="btn-close" aria-label={__('common.close')} />
            </div>
            <div className="modal-body">
                {!layer && (
                    <div className="alert alert-warning">
                        {__('dock.need_target')}
                    </div>
                )}
                {error && <div className="alert alert-danger">{error}</div>}

                {isIdentify ? (
                    <div className="mb-3">
                        <dl className="mb-0">
                            {Object.entries(displayProps).map(([key, value]) => (
                                <div key={key} className="mb-2">
                                    <dt className="text-muted small mb-0">{fieldDefs[key]?.alias || fieldLabel(key)}</dt>
                                    <dd className="mb-0">{String(value ?? '')}</dd>
                                </div>
                            ))}
                            {Object.keys(displayProps).length === 0 && (
                                <p className="text-muted mb-0">{__('maps.no_attributes')}</p>
                            )}
                        </dl>
                    </div>
                ) : (
                    Object.keys(displayProps).length > 0
                        ? Object.keys(displayProps).map((key) => {
                              const domain = domainOptions(key);
                              const current = displayProps[key] ?? '';
                              const options =
                                  current !== '' && !domain.includes(String(current))
                                      ? [String(current), ...domain]
                                      : domain;
                              return (
                                  <div className="mb-3" key={key}>
                                      <label className="form-label">{fieldDefs[key]?.alias || fieldLabel(key)}</label>
                                      {domain.length > 0 ? (
                                          <select
                                              className="form-control"
                                              value={String(current)}
                                              onChange={(event) =>
                                                  onPropertiesChange?.({
                                                      ...displayProps,
                                                      [key]: event.target.value,
                                                  })
                                              }
                                          >
                                              <option value="">—</option>
                                              {options.map((option) => (
                                                  <option key={option} value={option}>
                                                      {option}
                                                  </option>
                                              ))}
                                          </select>
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
                              );
                          })
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

                {(isIdentify || isEdit) && featureId && layer?.id && related.length > 0 ? (
                    <div className="mb-3">
                        <h6 className="mb-2">{__('maps.related_records')}</h6>
                        {related.map(({ relationship, columns, features }) => (
                            <div key={relationship.id} className="mb-3">
                                <div className="small text-muted mb-1">
                                    {relationship.name}
                                    {relationship.related_layer?.name
                                        ? ` · ${relationship.related_layer.name}`
                                        : ''}
                                </div>
                                {features.length === 0 ? (
                                    <p className="text-muted small mb-0">{__('maps.no_related')}</p>
                                ) : (
                                    <div className="table-responsive">
                                        <table className="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    {columns.map((column) => (
                                                        <th key={column}>{column}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {features.map((row, index) => (
                                                    <tr key={row.id ?? index}>
                                                        {columns.map((column) => (
                                                            <td key={column}>{row[column] ?? ''}</td>
                                                        ))}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                ) : null}

                {(isIdentify || isEdit) && featureId && layer?.id ? (
                    <div className="mb-3">
                        <h6 className="mb-2">{__('maps.attachments')}</h6>
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
                                <li className="text-muted small">{__('maps.no_attachments')}</li>
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
                        {__('dock.cancel')}
                    </button>
                    <button
                        type="button"
                        onClick={save}
                        className="btn btn-primary"
                        disabled={saving}
                    >
                        {saving ? __('dock.saving') : isEdit ? __('dock.update') : __('dock.save')}
                    </button>
                </div>
            ) : null}
        </div>
    );
}
