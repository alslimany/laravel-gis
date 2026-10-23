<?php

namespace App\SolutionPacks;

use App\Services\DashboardWidgetDocument;

/**
 * Structural validation for pack definitions built through the UI.
 * Returns Inertia-friendly error keys such as "definition.layers.0.name".
 */
class DefinitionValidator
{
    /**
     * Geometry types the installer can provision.
     *
     * @var list<string>
     */
    protected const GEOMETRY_TYPES = ['Point', 'LineString', 'Polygon', 'MultiPolygon', 'None'];

    /**
     * Field types the installer can provision.
     *
     * @var list<string>
     */
    protected const FIELD_TYPES = ['string', 'integer', 'float', 'boolean', 'date', 'datetime'];

    /**
     * @return array<string, string>
     */
    public function validate(array $definition): array
    {
        $errors = [];
        $layerNames = $this->validateLayers($definition, $errors);
        $this->validateRelationships($definition, $layerNames, $errors);
        $this->validateFeatures($definition, $layerNames, $errors);
        $this->validateMaps($definition, $layerNames, $errors);
        $this->validateForms($definition, $layerNames, $errors);
        $this->validateDashboards($definition, $layerNames, $errors);
        $this->validateWorkflow($definition, $errors);

        return $errors;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<string>
     */
    protected function validateLayers(array $definition, array &$errors): array
    {
        $layers = $definition['layers'] ?? [];
        if (! is_array($layers) || $layers === []) {
            $errors['definition.layers'] = 'Add at least one layer.';

            return [];
        }

        $names = [];
        foreach ($layers as $index => $layer) {
            $key = "definition.layers.{$index}";
            if (! is_array($layer)) {
                $errors[$key] = 'Invalid layer.';

                continue;
            }

            $name = trim((string) ($layer['name'] ?? ''));
            if ($name === '') {
                $errors["{$key}.name"] = 'Layer name is required.';
            } elseif (strlen($name) > 60) {
                $errors["{$key}.name"] = 'Keep layer names to 60 characters.';
            } elseif (in_array($name, $names, true)) {
                $errors["{$key}.name"] = 'Layer names must be unique.';
            } else {
                $names[] = $name;
            }

            if (! in_array((string) ($layer['geometry_type'] ?? ''), self::GEOMETRY_TYPES, true)) {
                $errors["{$key}.geometry_type"] = 'Choose a geometry type.';
            }

            $fields = $layer['fields'] ?? [];
            if (! is_array($fields)) {
                $errors["{$key}.fields"] = 'Fields must be a list.';
                $fields = [];
            }

            $fieldNames = [];
            foreach ($fields as $fieldIndex => $field) {
                $fieldKey = "{$key}.fields.{$fieldIndex}";
                if (! is_array($field)) {
                    $errors[$fieldKey] = 'Invalid field.';

                    continue;
                }

                $fieldName = (string) ($field['name'] ?? '');
                if ($fieldName === '') {
                    $errors["{$fieldKey}.name"] = 'Field name is required.';
                } elseif (! preg_match('/^[a-z_][a-z0-9_]*$/', $fieldName)) {
                    $errors["{$fieldKey}.name"] = 'Use lowercase letters, numbers, and underscores only.';
                } elseif (in_array($fieldName, $fieldNames, true)) {
                    $errors["{$fieldKey}.name"] = 'Field names must be unique within a layer.';
                } else {
                    $fieldNames[] = $fieldName;
                }

                if (! in_array((string) ($field['type'] ?? 'string'), self::FIELD_TYPES, true)) {
                    $errors["{$fieldKey}.type"] = 'Choose a field type.';
                }

                if (isset($field['domain']) && ! is_array($field['domain'])) {
                    $errors["{$fieldKey}.domain"] = 'Domain values must be a list.';
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $layerNames
     */
    protected function validateRelationships(array $definition, array $layerNames, array &$errors): void
    {
        $relationships = $definition['relationships'] ?? [];
        if (! is_array($relationships)) {
            $errors['definition.relationships'] = 'Relationships must be a list.';
            $relationships = [];
        }

        foreach ($relationships as $index => $relationship) {
            $key = "definition.relationships.{$index}";
            if (! is_array($relationship)) {
                $errors[$key] = 'Invalid relationship.';

                continue;
            }

            if (trim((string) ($relationship['name'] ?? '')) === '') {
                $errors["{$key}.name"] = 'Relationship name is required.';
            }

            foreach (['parent', 'child'] as $side) {
                $layer = (string) ($relationship[$side] ?? '');
                if ($layer === '') {
                    $errors["{$key}.{$side}"] = 'Choose a layer.';
                } elseif (! in_array($layer, $layerNames, true)) {
                    $errors["{$key}.{$side}"] = 'Layer is not defined in this pack.';
                }
            }

            if (($relationship['parent'] ?? null) !== null && ($relationship['parent'] ?? null) === ($relationship['child'] ?? null)) {
                $errors["{$key}.child"] = 'Parent and child must be different layers.';
            }

            $foreignKey = (string) ($relationship['foreign_key'] ?? '');
            if ($foreignKey === '') {
                $errors["{$key}.foreign_key"] = 'Foreign key is required.';
            } elseif (! preg_match('/^[a-z_][a-z0-9_]*$/', $foreignKey)) {
                $errors["{$key}.foreign_key"] = 'Use lowercase letters, numbers, and underscores only.';
            }
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $layerNames
     */
    protected function validateFeatures(array $definition, array $layerNames, array &$errors): void
    {
        $features = $definition['features'] ?? [];
        if (! is_array($features)) {
            $errors['definition.features'] = 'Sample features must be a list.';
            $features = [];
        }

        foreach ($features as $index => $feature) {
            $key = "definition.features.{$index}";
            if (! is_array($feature)) {
                $errors[$key] = 'Invalid sample feature.';

                continue;
            }

            $layer = (string) ($feature['layer'] ?? '');
            if ($layer === '') {
                $errors["{$key}.layer"] = 'Choose a layer.';
            } elseif (! in_array($layer, $layerNames, true)) {
                $errors["{$key}.layer"] = 'Layer is not defined in this pack.';
            }
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $layerNames
     */
    protected function validateMaps(array $definition, array $layerNames, array &$errors): void
    {
        $maps = $definition['maps'] ?? [];
        if (! is_array($maps)) {
            $errors['definition.maps'] = 'Maps must be a list.';
            $maps = [];
        }

        foreach ($maps as $index => $map) {
            $key = "definition.maps.{$index}";
            if (! is_array($map)) {
                $errors[$key] = 'Invalid map.';

                continue;
            }

            if (trim((string) ($map['name'] ?? '')) === '') {
                $errors["{$key}.name"] = 'Map name is required.';
            }

            $layers = $map['layers'] ?? [];
            if (! is_array($layers) || $layers === []) {
                $errors["{$key}.layers"] = 'Pick at least one layer.';
            } else {
                foreach ($layers as $layerIndex => $layer) {
                    if (! in_array((string) $layer, $layerNames, true)) {
                        $errors["{$key}.layers.{$layerIndex}"] = 'Layer is not defined in this pack.';
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $layerNames
     */
    protected function validateForms(array $definition, array $layerNames, array &$errors): void
    {
        $forms = $definition['forms'] ?? [];
        if (! is_array($forms)) {
            $errors['definition.forms'] = 'Forms must be a list.';
            $forms = [];
        }

        $schemaTypes = ['text', 'textarea', 'number', 'select', 'checkbox', 'date'];
        foreach ($forms as $index => $form) {
            $key = "definition.forms.{$index}";
            if (! is_array($form)) {
                $errors[$key] = 'Invalid form.';

                continue;
            }

            if (trim((string) ($form['name'] ?? '')) === '') {
                $errors["{$key}.name"] = 'Form name is required.';
            }

            $layer = (string) ($form['layer'] ?? '');
            if ($layer === '') {
                $errors["{$key}.layer"] = 'Choose a layer.';
            } elseif (! in_array($layer, $layerNames, true)) {
                $errors["{$key}.layer"] = 'Layer is not defined in this pack.';
            }

            $schema = $form['schema'] ?? [];
            if (! is_array($schema)) {
                $errors["{$key}.schema"] = 'Schema must be a list.';
                $schema = [];
            }

            foreach ($schema as $fieldIndex => $field) {
                $fieldKey = "{$key}.schema.{$fieldIndex}";
                if (! is_array($field)) {
                    $errors[$fieldKey] = 'Invalid field.';

                    continue;
                }

                if (trim((string) ($field['name'] ?? '')) === '') {
                    $errors["{$fieldKey}.name"] = 'Field name is required.';
                }
                if (! in_array((string) ($field['type'] ?? 'text'), $schemaTypes, true)) {
                    $errors["{$fieldKey}.type"] = 'Choose a field type.';
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $layerNames
     */
    protected function validateDashboards(array $definition, array $layerNames, array &$errors): void
    {
        $dashboards = $definition['dashboards'] ?? [];
        if (! is_array($dashboards)) {
            $errors['definition.dashboards'] = 'Dashboards must be a list.';
            $dashboards = [];
        }

        foreach ($dashboards as $index => $dashboard) {
            $key = "definition.dashboards.{$index}";
            if (! is_array($dashboard)) {
                $errors[$key] = 'Invalid dashboard.';

                continue;
            }

            if (trim((string) ($dashboard['name'] ?? '')) === '') {
                $errors["{$key}.name"] = 'Dashboard name is required.';
            }

            $widgets = $dashboard['widgets'] ?? [];
            if (! is_array($widgets)) {
                $errors["{$key}.widgets"] = 'Widgets must be a list.';
                $widgets = [];
            }

            foreach ($widgets as $widgetIndex => $widget) {
                $widgetKey = "{$key}.widgets.{$widgetIndex}";
                if (! is_array($widget)) {
                    $errors[$widgetKey] = 'Invalid widget.';

                    continue;
                }

                $type = (string) ($widget['type'] ?? 'indicator');
                if (! in_array($type, DashboardWidgetDocument::TYPES, true)) {
                    $errors["{$widgetKey}.type"] = 'Choose a widget type.';
                }

                $layer = $widget['layer'] ?? null;
                if ($layer !== null && ! in_array((string) $layer, $layerNames, true)) {
                    $errors["{$widgetKey}.layer"] = 'Layer is not defined in this pack.';
                }
            }
        }
    }

    /**
     * Workflow steps are an optional runbook chaining workspace artifacts.
     *
     * @param  array<string, string>  $errors
     */
    protected function validateWorkflow(array $definition, array &$errors): void
    {
        $workflow = $definition['workflow'] ?? [];
        if (! is_array($workflow)) {
            $errors['definition.workflow'] = 'Workflow steps must be a list.';
            $workflow = [];
        }

        $mapNames = array_column($definition['maps'] ?? [], 'name');
        $formNames = array_column($definition['forms'] ?? [], 'name');
        $dashboardNames = array_column($definition['dashboards'] ?? [], 'name');
        $targets = ['map' => $mapNames, 'form' => $formNames, 'dashboard' => $dashboardNames];

        foreach ($workflow as $index => $step) {
            $key = "definition.workflow.{$index}";
            if (! is_array($step)) {
                $errors[$key] = 'Invalid workflow step.';

                continue;
            }

            if (trim((string) ($step['title'] ?? '')) === '') {
                $errors["{$key}.title"] = 'Step title is required.';
            }

            $kind = (string) ($step['kind'] ?? 'text');
            if (! in_array($kind, WorkflowResolver::KINDS, true)) {
                $errors["{$key}.kind"] = 'Choose a step type.';

                continue;
            }

            if ($kind === 'text') {
                continue;
            }

            $target = (string) ($step['target'] ?? '');
            if ($target === '') {
                $errors["{$key}.target"] = 'Choose a target.';
            } elseif (! in_array($target, $targets[$kind], true)) {
                $errors["{$key}.target"] = 'Target is not defined in this pack.';
            }
        }
    }
}
