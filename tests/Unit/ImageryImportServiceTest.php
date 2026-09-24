<?php

namespace Tests\Unit;

use App\Services\ImageryImportService;
use Tests\TestCase;

class ImageryImportServiceTest extends TestCase
{
    public function test_jpeg_without_sidecars_has_no_map_position(): void
    {
        $error = ImageryImportService::georeferenceError(['photo.jpg']);

        $this->assertNotNull($error);
        $this->assertStringContainsString('world file', $error);
    }

    public function test_jpeg_with_world_file_and_prj_is_georeferenced(): void
    {
        $this->assertNull(ImageryImportService::georeferenceError([
            'photo.jpg',
            'photo.jgw',
            'photo.prj',
        ]));
    }

    public function test_geotiff_does_not_require_a_world_file(): void
    {
        $this->assertNull(ImageryImportService::georeferenceError(['scene.tif']));
        $this->assertNull(ImageryImportService::georeferenceError(['package.zip']));
    }

    public function test_granule_name_keeps_a_single_acquisition_date(): void
    {
        $name = ImageryImportService::granuleFileName('imgabcdefgh', '2026-09-23', 'wxyz');

        $this->assertSame('imgabcdefgh_20260923_wxyz.tif', $name);
        preg_match_all('/[0-9]{8}/', $name, $matches);
        $this->assertCount(1, $matches[0]);
    }
}
