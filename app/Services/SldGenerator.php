<?php

namespace App\Services;

use App\Models\Layer;

/**
 * Build GeoServer SLD XML from client style_config.
 */
class SldGenerator
{
    public function generate(Layer $layer, array $styleConfig): string
    {
        $renderer = $styleConfig['renderer'] ?? 'simple';
        $geomType = strtolower((string) ($layer->geometry_type ?? 'polygon'));
        $name = $this->xml($layer->geoserver_layer_name ?: $layer->table_name);

        $rules = match ($renderer) {
            'unique' => $this->uniqueRules($styleConfig, $geomType),
            'graduated' => $this->graduatedRules($styleConfig, $geomType),
            'heatmap' => $this->heatmapFeatureType($styleConfig),
            default => $this->simpleRule($styleConfig, $geomType),
        };

        if ($renderer === 'heatmap') {
            return <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0"
  xmlns="http://www.opengis.net/sld"
  xmlns:ogc="http://www.opengis.net/ogc"
  xmlns:xlink="http://www.w3.org/1999/xlink"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns:gml="http://www.opengis.net/gml">
  <NamedLayer>
    <Name>{$name}</Name>
    <UserStyle>
      <Name>custom_style</Name>
      <FeatureTypeStyle>
{$rules}
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;
        }

        $labelRule = $this->labelRule($styleConfig);
        if ($labelRule) {
            $rules .= "\n".$labelRule;
        }

        return <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0"
  xmlns="http://www.opengis.net/sld"
  xmlns:ogc="http://www.opengis.net/ogc"
  xmlns:xlink="http://www.w3.org/1999/xlink"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns:gml="http://www.opengis.net/gml">
  <NamedLayer>
    <Name>{$name}</Name>
    <UserStyle>
      <Name>custom_style</Name>
      <FeatureTypeStyle>
{$rules}
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;
    }

    protected function simpleRule(array $config, string $geomType): string
    {
        $symbol = $config['symbol'] ?? $config;
        $symbolizer = $this->symbolizer($symbol, $geomType);

        return "        <Rule>\n{$symbolizer}\n        </Rule>";
    }

    protected function uniqueRules(array $config, string $geomType): string
    {
        $field = $config['field'] ?? '';
        $entries = $config['uniqueValues'] ?? [];
        if ($field === '' || $entries === []) {
            return $this->simpleRule($config, $geomType);
        }

        $rules = '';
        foreach ($entries as $entry) {
            $value = $this->xml((string) ($entry['value'] ?? ''));
            $symbol = array_merge($config['symbol'] ?? [], [
                'fill_color' => $entry['color'] ?? $entry['fill'] ?? ($config['symbol']['fillColor'] ?? '#3388ff'),
                'fillColor' => $entry['color'] ?? $entry['fill'] ?? null,
                'icon' => $entry['icon'] ?? ($config['symbol']['icon'] ?? null),
            ]);
            $symbolizer = $this->symbolizer($symbol, $geomType);
            $property = $this->xml($field);
            $rules .= <<<RULE
        <Rule>
          <ogc:Filter>
            <ogc:PropertyIsEqualTo>
              <ogc:PropertyName>{$property}</ogc:PropertyName>
              <ogc:Literal>{$value}</ogc:Literal>
            </ogc:PropertyIsEqualTo>
          </ogc:Filter>
{$symbolizer}
        </Rule>

RULE;
        }

        return $rules;
    }

    protected function graduatedRules(array $config, string $geomType): string
    {
        $field = $config['field'] ?? '';
        $classes = $config['classes'] ?? [];
        if ($field === '' || $classes === []) {
            return $this->simpleRule($config, $geomType);
        }

        $rules = '';
        $property = $this->xml($field);
        foreach ($classes as $class) {
            $min = $this->xml((string) ($class['min'] ?? 0));
            $max = $this->xml((string) ($class['max'] ?? 0));
            $symbol = array_merge($config['symbol'] ?? [], [
                'fill_color' => $class['color'] ?? null,
                'fillColor' => $class['color'] ?? null,
            ]);
            $symbolizer = $this->symbolizer($symbol, $geomType);
            $rules .= <<<RULE
        <Rule>
          <ogc:Filter>
            <ogc:And>
              <ogc:PropertyIsGreaterThanOrEqualTo>
                <ogc:PropertyName>{$property}</ogc:PropertyName>
                <ogc:Literal>{$min}</ogc:Literal>
              </ogc:PropertyIsGreaterThanOrEqualTo>
              <ogc:PropertyIsLessThanOrEqualTo>
                <ogc:PropertyName>{$property}</ogc:PropertyName>
                <ogc:Literal>{$max}</ogc:Literal>
              </ogc:PropertyIsLessThanOrEqualTo>
            </ogc:And>
          </ogc:Filter>
{$symbolizer}
        </Rule>

RULE;
        }

        return $rules;
    }

    protected function heatmapFeatureType(array $config): string
    {
        $heatmap = $config['heatmap'] ?? [];
        $radius = (float) ($heatmap['radius'] ?? 8);
        $weight = $this->xml((string) ($heatmap['weight'] ?? $config['field'] ?? '1'));

        return <<<XML
        <Transformation>
          <ogc:Function name="vec:Heatmap">
            <ogc:Function name="parameter">
              <ogc:Literal>data</ogc:Literal>
            </ogc:Function>
            <ogc:Function name="parameter">
              <ogc:Literal>radiusPixels</ogc:Literal>
              <ogc:Literal>{$radius}</ogc:Literal>
            </ogc:Function>
            <ogc:Function name="parameter">
              <ogc:Literal>weightAttr</ogc:Literal>
              <ogc:Literal>{$weight}</ogc:Literal>
            </ogc:Function>
          </ogc:Function>
        </Transformation>
        <Rule>
          <RasterSymbolizer>
            <Geometry>
              <ogc:PropertyName>geom</ogc:PropertyName>
            </Geometry>
            <Opacity>0.75</Opacity>
            <ColorMap type="ramp">
              <ColorMapEntry color="#FFFFFF" quantity="0" opacity="0"/>
              <ColorMapEntry color="#0000FF" quantity="0.1"/>
              <ColorMapEntry color="#00FFFF" quantity="0.3"/>
              <ColorMapEntry color="#00FF00" quantity="0.5"/>
              <ColorMapEntry color="#FFFF00" quantity="0.7"/>
              <ColorMapEntry color="#FF0000" quantity="1.0"/>
            </ColorMap>
          </RasterSymbolizer>
        </Rule>
XML;
    }

    protected function labelRule(array $config): ?string
    {
        $labels = $config['labels'] ?? [];
        if (empty($labels['enabled']) || empty($labels['field'])) {
            return null;
        }

        $field = $this->xml($labels['field']);
        $color = $this->xml($labels['color'] ?? '#222222');

        return <<<RULE
        <Rule>
          <TextSymbolizer>
            <Label>
              <ogc:PropertyName>{$field}</ogc:PropertyName>
            </Label>
            <Font>
              <CssParameter name="font-family">Arial</CssParameter>
              <CssParameter name="font-size">12</CssParameter>
            </Font>
            <Fill>
              <CssParameter name="fill">{$color}</CssParameter>
            </Fill>
          </TextSymbolizer>
        </Rule>
RULE;
    }

    protected function symbolizer(array $symbol, string $geomType): string
    {
        $fill = $this->xml(
            $symbol['fill_color']
            ?? $symbol['fillColor']
            ?? '#3388ff'
        );
        $stroke = $this->xml(
            $symbol['stroke_color']
            ?? $symbol['strokeColor']
            ?? '#000000'
        );
        $strokeWidth = (float) ($symbol['stroke_width'] ?? $symbol['strokeWidth'] ?? 1);
        $fillOpacity = (float) ($symbol['fill_opacity'] ?? $symbol['fillOpacity'] ?? 0.5);
        $radius = (float) ($symbol['pointRadius'] ?? $symbol['point_radius'] ?? 6);

        if (str_contains($geomType, 'point')) {
            $icon = (string) ($symbol['icon'] ?? '');
            $size = (float) ($symbol['size'] ?? ($radius * 2));
            if ($icon !== '' && $icon !== 'circle' && MapIconCatalog::allows($icon)) {
                $href = $this->xml(MapIconCatalog::url($icon, $fill));

                return <<<SYM
          <PointSymbolizer>
            <Graphic>
              <ExternalGraphic>
                <OnlineResource xlink:href="{$href}"/>
                <Format>image/svg+xml</Format>
              </ExternalGraphic>
              <Size>{$size}</Size>
            </Graphic>
          </PointSymbolizer>
SYM;
            }

            return <<<SYM
          <PointSymbolizer>
            <Graphic>
              <Mark>
                <WellKnownName>circle</WellKnownName>
                <Fill>
                  <CssParameter name="fill">{$fill}</CssParameter>
                  <CssParameter name="fill-opacity">{$fillOpacity}</CssParameter>
                </Fill>
                <Stroke>
                  <CssParameter name="stroke">{$stroke}</CssParameter>
                  <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
                </Stroke>
              </Mark>
              <Size>{$radius}</Size>
            </Graphic>
          </PointSymbolizer>
SYM;
        }

        if (str_contains($geomType, 'line')) {
            return <<<SYM
          <LineSymbolizer>
            <Stroke>
              <CssParameter name="stroke">{$stroke}</CssParameter>
              <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
            </Stroke>
          </LineSymbolizer>
SYM;
        }

        return <<<SYM
          <PolygonSymbolizer>
            <Fill>
              <CssParameter name="fill">{$fill}</CssParameter>
              <CssParameter name="fill-opacity">{$fillOpacity}</CssParameter>
            </Fill>
            <Stroke>
              <CssParameter name="stroke">{$stroke}</CssParameter>
              <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
            </Stroke>
          </PolygonSymbolizer>
SYM;
    }

    protected function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
