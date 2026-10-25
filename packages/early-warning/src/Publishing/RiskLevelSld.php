<?php

namespace Lumina\EarlyWarning\Publishing;

/** Unique-value SLD for green / yellow / orange / red basin risk. */
final class RiskLevelSld
{
    public static function xml(): string
    {
        $rules = '';
        foreach ([
            'green' => '#22c55e',
            'yellow' => '#eab308',
            'orange' => '#f97316',
            'red' => '#ef4444',
        ] as $level => $color) {
            $rules .= <<<RULE
        <Rule>
          <Name>{$level}</Name>
          <ogc:Filter>
            <ogc:PropertyIsEqualTo>
              <ogc:PropertyName>risk_level</ogc:PropertyName>
              <ogc:Literal>{$level}</ogc:Literal>
            </ogc:PropertyIsEqualTo>
          </ogc:Filter>
          <PolygonSymbolizer>
            <Fill>
              <CssParameter name="fill">{$color}</CssParameter>
              <CssParameter name="fill-opacity">0.55</CssParameter>
            </Fill>
            <Stroke>
              <CssParameter name="stroke">#1f2937</CssParameter>
              <CssParameter name="stroke-width">1</CssParameter>
            </Stroke>
          </PolygonSymbolizer>
        </Rule>

RULE;
        }

        return <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0"
  xmlns="http://www.opengis.net/sld"
  xmlns:ogc="http://www.opengis.net/ogc"
  xmlns:xlink="http://www.w3.org/1999/xlink"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.opengis.net/sld StyledLayerDescriptor.xsd">
  <NamedLayer>
    <Name>ew_basin_risk</Name>
    <UserStyle>
      <Name>ew_basin_risk</Name>
      <Title>Basin flood risk (advisory)</Title>
      <FeatureTypeStyle>
{$rules}      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;
    }
}
