<?php

namespace Tests\Unit;

use App\Services\DashboardWidgetDocument;
use Tests\TestCase;

class DashboardWidgetDocumentTest extends TestCase
{
    public function test_map_link_is_optional_and_source_is_explicit(): void
    {
        $widgets = (new DashboardWidgetDocument)->normalize([
            ['type' => 'text', 'title' => 'Note', 'body' => 'Hello'],
            ['type' => 'map', 'title' => 'Saved', 'map_id' => '12'],
            ['type' => 'map', 'title' => 'Layer map', 'source' => 'layer', 'layer_id' => 4],
            ['type' => 'map', 'title' => 'Blank'],
            ['type' => 'map', 'title' => 'Rejected source', 'source' => 'form'],
            ['type' => 'kpi', 'title' => 'Count'],
            ['type' => 'line', 'title' => 'Trend'],
        ]);

        $this->assertSame('text', $widgets[0]['type']);
        $this->assertSame('Hello', $widgets[0]['body']);
        $this->assertArrayNotHasKey('form_id', $widgets[0]);
        $this->assertArrayNotHasKey('layer_id', $widgets[0]);

        $this->assertSame('map', $widgets[1]['source']);
        $this->assertSame(12, $widgets[1]['map_id']);

        $this->assertSame('layer', $widgets[2]['source']);
        $this->assertSame(4, $widgets[2]['layer_id']);

        $this->assertSame('layer', $widgets[3]['source']);
        $this->assertArrayNotHasKey('map_id', $widgets[3]);
        $this->assertSame('layer', $widgets[4]['source']);

        $this->assertSame('indicator', $widgets[5]['type']);
        $this->assertSame('serial', $widgets[6]['type']);
        $this->assertSame('line', $widgets[6]['chart_style']);
    }

    public function test_analysis_source_is_kept_for_data_widgets_and_ignored_on_maps(): void
    {
        $widgets = (new DashboardWidgetDocument)->normalize([
            ['type' => 'indicator', 'title' => 'From analysis', 'source' => 'analysis', 'analysis_id' => '4'],
            ['type' => 'serial', 'title' => 'Chart'],
            ['type' => 'map', 'title' => 'Map', 'source' => 'analysis', 'analysis_id' => 3],
            ['type' => 'text', 'title' => 'Note', 'source' => 'analysis', 'analysis_id' => 8, 'body' => 'Hello'],
        ]);

        $this->assertSame('analysis', $widgets[0]['source']);
        $this->assertSame(4, $widgets[0]['analysis_id']);
        $this->assertArrayNotHasKey('layer_id', $widgets[0]);

        $this->assertArrayNotHasKey('source', $widgets[1]);
        $this->assertArrayNotHasKey('analysis_id', $widgets[1]);

        $this->assertSame('layer', $widgets[2]['source']);
        $this->assertArrayNotHasKey('analysis_id', $widgets[2]);

        $this->assertSame('text', $widgets[3]['type']);
        $this->assertArrayNotHasKey('source', $widgets[3]);
        $this->assertArrayNotHasKey('analysis_id', $widgets[3]);
        $this->assertSame('Hello', $widgets[3]['body']);
    }
}
