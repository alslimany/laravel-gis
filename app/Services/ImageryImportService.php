<?php

namespace App\Services;

use App\Models\DataImport;
use App\Models\Layer;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ImageryImportService
{
    /**
     * JPEG and PNG have no map position unless a world file and .prj travel with them.
     * GeoTIFF and zip packages are accepted here and checked again by GDAL.
     *
     * @param  list<string>  $filenames
     */
    public static function georeferenceError(array $filenames): ?string
    {
        $lower = array_map(static fn (string $name) => strtolower(basename($name)), $filenames);

        $extension = static fn (string $name): string => strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $has = static function (array $extensions) use ($lower, $extension): bool {
            foreach ($lower as $name) {
                if (in_array($extension($name), $extensions, true)) {
                    return true;
                }
            }

            return false;
        };

        if ($has(['zip', 'tif', 'tiff'])) {
            return null;
        }

        $rasters = array_values(array_filter(
            $lower,
            static fn (string $name) => in_array($extension($name), ['jpg', 'jpeg', 'png'], true)
        ));

        if ($rasters === []) {
            return 'Choose a GeoTIFF, a zip of a GeoTIFF, or a JPEG/PNG with its world file and .prj.';
        }

        foreach ($rasters as $raster) {
            $stem = pathinfo($raster, PATHINFO_FILENAME);
            $worlds = array_map('strtolower', self::worldFileNames($raster));
            $hasWorld = count(array_intersect($worlds, $lower)) > 0;
            $hasPrj = in_array($stem.'.prj', $lower, true);

            if (! $hasWorld || ! $hasPrj) {
                return 'This image has no map position. Add the world file and .prj that match '.$raster.', or upload a GeoTIFF.';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function worldFileNames(string $rasterName): array
    {
        $stem = pathinfo($rasterName, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($rasterName, PATHINFO_EXTENSION));

        $specific = match ($extension) {
            'jpg', 'jpeg' => [$stem.'.jgw', $stem.'.jpgw'],
            'png' => [$stem.'.pgw', $stem.'.pngw'],
            'tif', 'tiff' => [$stem.'.tfw', $stem.'.tifw'],
            default => [],
        };

        return array_merge($specific, [$stem.'.wld']);
    }

    /**
     * Granule names keep the acquisition date as the only 8-digit run so the
     * mosaic can sort newest-on-top.
     */
    public static function granuleFileName(string $store, string $acquiredAt, ?string $suffix = null): string
    {
        $date = Carbon::parse($acquiredAt)->format('Ymd');
        $suffix = strtolower($suffix ?: 'abcd');
        $suffix = preg_replace('/[^a-z]/', '', $suffix) ?: 'abcd';
        $suffix = substr($suffix, 0, 4);

        return $store.'_'.$date.'_'.$suffix.'.tif';
    }

    public static function storeName(): string
    {
        $letters = preg_replace('/[^a-z]/', '', str_replace('-', '', (string) Str::uuid())) ?: '';
        $letters = substr($letters.'abcdefghij', 0, 8);

        return 'img'.$letters;
    }

    public function import(DataImport $import): Layer
    {
        $source = $this->resolveSource($import);
        $import->updateProgress(15);

        $info = $this->inspect($source['path']);
        $import->updateProgress(35);

        $cog = $this->buildCog($source['path']);
        $import->updateProgress(70);

        try {
            return $this->publish($import, $cog, $info);
        } finally {
            if (is_file($cog)) {
                @unlink($cog);
            }
            if (! empty($source['cleanup']) && is_dir($source['cleanup'])) {
                $this->deleteDirectory($source['cleanup']);
            }
        }
    }

    /**
     * @return array{path: string, cleanup: ?string}
     */
    protected function resolveSource(DataImport $import): array
    {
        $disk = config('dataimport.upload_disk', 'local');
        $path = Storage::disk($disk)->path($import->file_path);

        if (! is_file($path)) {
            throw new Exception('The uploaded imagery file is missing.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            return ['path' => $path, 'cleanup' => null];
        }

        $temp = storage_path('app/imagery/tmp/'.Str::uuid());
        if (! mkdir($temp, 0775, true) && ! is_dir($temp)) {
            throw new Exception('Could not prepare a folder for the imagery package.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new Exception('The zip package could not be opened.');
        }
        $zip->extractTo($temp);
        $zip->close();

        $raster = $this->findRaster($temp);
        if ($raster === null) {
            $this->deleteDirectory($temp);
            throw new Exception('The zip has no GeoTIFF, JPEG, or PNG.');
        }

        return ['path' => $raster, 'cleanup' => $temp];
    }

    protected function findRaster(string $directory): ?string
    {
        $preferred = ['tif', 'tiff', 'jpg', 'jpeg', 'png'];
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (in_array($extension, $preferred, true)) {
                $found[$extension][] = $file->getPathname();
            }
        }

        foreach ($preferred as $extension) {
            if (! empty($found[$extension])) {
                return $found[$extension][0];
            }
        }

        return null;
    }

    /**
     * @return array{bbox: ?array, width: int, height: int, bands: int, crs: string}
     */
    protected function inspect(string $path): array
    {
        $process = new \Symfony\Component\Process\Process([
            config('imagery.gdalinfo_path', 'gdalinfo'),
            '-json',
            $path,
        ]);
        $process->setTimeout((int) config('imagery.timeout', 1800));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new Exception(trim($process->getErrorOutput()) ?: 'GDAL could not read this image.');
        }

        $json = json_decode($process->getOutput(), true);
        if (! is_array($json)) {
            throw new Exception('GDAL did not return image information.');
        }

        $crs = $json['coordinateSystem']['wkt'] ?? ($json['stac']['proj:epsg'] ?? null);
        $extent = $json['wgs84Extent']['coordinates'][0] ?? null;

        if (! $crs || ! is_array($extent)) {
            throw new Exception('This image has no map position. Export it as a GeoTIFF, or add a world file and a .prj.');
        }

        $xs = array_column($extent, 0);
        $ys = array_column($extent, 1);

        return [
            'bbox' => [min($xs), min($ys), max($xs), max($ys)],
            'width' => (int) ($json['size'][0] ?? 0),
            'height' => (int) ($json['size'][1] ?? 0),
            'bands' => count($json['bands'] ?? []),
            'crs' => 'EPSG:4326',
        ];
    }

    protected function buildCog(string $source): string
    {
        $directory = storage_path('app/imagery/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $target = $directory.'/'.Str::uuid().'.tif';
        $warp = config('imagery.gdalwarp_path', 'gdalwarp');

        $cog = new \Symfony\Component\Process\Process([
            $warp,
            '-t_srs', 'EPSG:3857',
            '-r', 'bilinear',
            '-of', 'COG',
            '-co', 'COMPRESS=DEFLATE',
            '-co', 'BIGTIFF=IF_SAFER',
            $source,
            $target,
        ]);
        $cog->setTimeout((int) config('imagery.timeout', 1800));
        $cog->run();

        if ($cog->isSuccessful() && is_file($target)) {
            return $target;
        }

        if (is_file($target)) {
            @unlink($target);
        }

        $tiff = new \Symfony\Component\Process\Process([
            $warp,
            '-t_srs', 'EPSG:3857',
            '-r', 'bilinear',
            '-of', 'GTiff',
            '-co', 'TILED=YES',
            '-co', 'COMPRESS=DEFLATE',
            '-co', 'BIGTIFF=IF_SAFER',
            $source,
            $target,
        ]);
        $tiff->setTimeout((int) config('imagery.timeout', 1800));
        $tiff->run();

        if (! $tiff->isSuccessful() || ! is_file($target)) {
            $detail = trim($cog->getErrorOutput()."\n".$tiff->getErrorOutput());
            throw new Exception($detail !== '' ? $detail : 'The image could not be prepared for the map.');
        }

        $overviews = new \Symfony\Component\Process\Process([
            config('imagery.gdaladdo_path', 'gdaladdo'),
            '-r', 'average',
            $target,
            '2', '4', '8', '16',
        ]);
        $overviews->setTimeout((int) config('imagery.timeout', 1800));
        $overviews->run();

        return $target;
    }

    /**
     * @param  array{bbox: ?array, width: int, height: int, bands: int, crs: string}  $info
     */
    protected function publish(DataImport $import, string $cog, array $info): Layer
    {
        $acquiredAt = (string) ($import->metadata['acquired_at'] ?? now()->toDateString());
        $targetId = $import->metadata['target_layer_id'] ?? null;
        $layer = $targetId ? Layer::query()->find($targetId) : null;

        if ($layer && ($layer->organization_id !== $import->organization_id || $layer->geometry_type !== 'Raster')) {
            throw new Exception('That imagery layer cannot take this scene.');
        }

        $store = $layer?->table_name ?: self::storeName();
        $workspace = 'org_'.$import->organization_id;
        $relative = 'imagery/'.$workspace.'/'.$store;
        $mosaic = storage_path('app/'.$relative);

        if (! is_dir($mosaic) && ! mkdir($mosaic, 0777, true) && ! is_dir($mosaic)) {
            throw new Exception('Could not create the imagery mosaic folder.');
        }
        chmod($mosaic, 0777);

        $this->writeMosaicIndex($mosaic, $store);

        $suffix = substr(preg_replace('/[^a-z]/', '', Str::lower(Str::random(8))) ?: 'abcd', 0, 4);
        $granuleName = self::granuleFileName($store, $acquiredAt, $suffix);
        $granulePath = $mosaic.'/'.$granuleName;

        if (! copy($cog, $granulePath)) {
            throw new Exception('Could not store the prepared image.');
        }
        chmod($granulePath, 0666);

        $geoserverDirectory = rtrim(config('imagery.geoserver_path'), '/').'/'.$workspace.'/'.$store;
        $directoryUrl = 'file://'.$geoserverDirectory;
        $granuleUrl = $directoryUrl.'/'.$granuleName;

        $geoServer = app(GeoServerService::class);
        $title = $import->metadata['name'] ?? ($layer?->name ?? pathinfo($import->file_name, PATHINFO_FILENAME));

        if ($layer && $layer->published && $layer->geoserver_workspace) {
            $geoServer->harvestImageMosaicGranule($layer->geoserver_workspace, $store, $granuleUrl);
        } else {
            $geoServer->publishImageMosaic($workspace, $store, $directoryUrl, $title);
        }

        $metadata = $layer?->metadata ?? [];
        $granules = $metadata['granules'] ?? [];
        $granules[] = [
            'file' => $granuleName,
            'acquired_at' => $acquiredAt,
            'source' => $import->file_name,
        ];

        $bbox = $this->unionBbox($metadata['bbox'] ?? null, $info['bbox']);

        $attributes = [
            'name' => $layer->name ?? $title,
            'description' => 'Imagery layer. Where scenes overlap, the latest acquisition date is drawn on top of the satellite basemap.',
            'table_name' => $store,
            'geometry_type' => 'Raster',
            'feature_count' => count($granules),
            'geoserver_workspace' => $workspace,
            'geoserver_layer_name' => $store,
            'published' => true,
            'published_at' => now(),
            'metadata' => array_merge($metadata, [
                'kind' => 'imagery',
                'bbox' => $bbox,
                'crs' => 'EPSG:3857',
                'bands' => $info['bands'],
                'width' => $info['width'],
                'height' => $info['height'],
                'acquired_at' => $acquiredAt,
                'granules' => $granules,
                'wms_params' => ['SORTING' => 'acquired D'],
                'source' => 'imagery',
                'import_id' => $import->id,
            ]),
        ];

        if ($layer) {
            $layer->update($attributes);
        } else {
            $layer = Layer::create(array_merge($attributes, [
                'user_id' => $import->user_id,
                'organization_id' => $import->organization_id,
                'style_config' => ['renderer' => 'imagery'],
            ]));
        }

        $importMetadata = $import->metadata ?? [];
        $importMetadata['layer_id'] = $layer->id;
        $import->update(['metadata' => $importMetadata]);

        Log::info('Published imagery granule', [
            'import_id' => $import->id,
            'layer_id' => $layer->id,
            'granule' => $granuleName,
        ]);

        return $layer->fresh();
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $existing
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $next
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected function unionBbox(?array $existing, ?array $next): ?array
    {
        if ($next === null) {
            return $existing;
        }
        if ($existing === null) {
            return $next;
        }

        return [
            min($existing[0], $next[0]),
            min($existing[1], $next[1]),
            max($existing[2], $next[2]),
            max($existing[3], $next[3]),
        ];
    }

    protected function writeMosaicIndex(string $directory, string $store): void
    {
        $indexer = $directory.'/indexer.properties';
        if (! is_file($indexer)) {
            file_put_contents($indexer, implode("\n", [
                'Caching=false',
                'AbsolutePath=false',
                'Schema=*the_geom:Polygon,location:String,acquired:java.util.Date',
                'PropertyCollectors=TimestampFileNameExtractorSPI[timeregex](acquired)',
                'TimeAttribute=acquired',
                'Name='.$store,
                '',
            ]));
        }

        $regex = $directory.'/timeregex.properties';
        if (! is_file($regex)) {
            file_put_contents($regex, "regex=[0-9]{8}\nformat=yyyyMMdd\n");
        }
    }

    protected function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
