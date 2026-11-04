<?php

namespace Lumina\Disaster\Tests;

use Lumina\Disaster\Publishing\OpsLayerPublisher;
use Tests\TestCase;

/** QA #7: closures are 4px dark lines over a white casing, in MVT style_config and GeoServer SLD alike. */
class ClosuresStyleTest extends TestCase
{
    public function test_style_config_has_thick_dark_line_and_white_casing(): void
    {
        $style = OpsLayerPublisher::closuresStyle();

        $this->assertSame(4, $style['symbol']['strokeWidth']);
        $this->assertSame('#ffffff', $style['symbol']['casingColor']);
        $this->assertSame(7, $style['symbol']['casingWidth']);
        $this->assertSame('#991b1b', collect($style['uniqueValues'])->firstWhere('value', 'major')['color']);
    }

    public function test_sld_draws_casing_under_the_severity_coloured_line(): void
    {
        $sld = OpsLayerPublisher::closuresSld('dm_org_closures');

        $this->assertSame(6, substr_count($sld, '<LineSymbolizer>'), 'casing + line per severity rule');
        $major = substr($sld, strpos($sld, '<ogc:Literal>major</ogc:Literal>'));
        $major = substr($major, 0, strpos($major, '</Rule>'));
        $this->assertLessThan(strpos($major, '#991b1b'), strpos($major, '#ffffff'), 'white casing is drawn first');
        $this->assertStringContainsString('<CssParameter name="stroke-width">4</CssParameter>', $major);
        $this->assertStringContainsString('<CssParameter name="stroke-width">7</CssParameter>', $major);
        $this->assertNotFalse(simplexml_load_string($sld));
    }

    public function test_short_closures_are_emphasised_when_zoomed_out(): void
    {
        $symbol = OpsLayerPublisher::closuresStyle()['symbol'];

        $this->assertSame(14, $symbol['minLengthPx']);
        $this->assertSame(20, $symbol['lowZoomResolution']);
        $this->assertSame(2, $symbol['lowZoomExtraWidth']);
        $this->assertSame(7, $symbol['casingWidth']);
    }
}
