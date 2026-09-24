@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-end mb-3">
                <div>
                    <h1 class="h3 mb-1">Add data</h1>
                    <p class="mb-0" style="color:#3f4c5e; max-width: 62ch;">
                        @if($kind === 'imagery')
                            Publish a georeferenced scene above the satellite basemap. A GeoTIFF, or a JPEG/PNG with its world file and .prj, is prepared as map tiles. Where scenes overlap, the latest capture date stays on top. The world imagery basemap itself is still the maintained Esri mosaic.
                        @else
                            Drop a dataset the way ArcGIS publishes a feature layer. A zipped shapefile, KML, KMZ, GeoJSON, CSV, or Excel file is inspected, loaded into PostGIS, and published as a layer you can open on the map.
                        @endif
                    </p>
                </div>
                <a href="{{ route('imports.index') }}" class="btn btn-outline-secondary btn-sm">Back</a>
            </div>

            <div class="d-flex gap-2 mb-3" role="tablist" aria-label="What to add">
                <a href="{{ route('imports.create') }}" class="btn btn-sm {{ $kind === 'imagery' ? 'btn-outline-secondary' : 'btn-primary' }}">Feature data</a>
                <a href="{{ route('imports.create', ['kind' => 'imagery']) }}" class="btn btn-sm {{ $kind === 'imagery' ? 'btn-primary' : 'btn-outline-secondary' }}">Imagery</a>
            </div>

            @if($errors->any())
                <div class="alert alert-danger">
                    <strong>{{ $kind === 'imagery' ? 'The image was not accepted.' : 'The dataset was not accepted.' }}</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($kind === 'imagery')
                <form action="{{ route('imports.imagery.store') }}" method="POST" enctype="multipart/form-data" id="imageryForm">
                    @csrf
                    <input type="hidden" name="_kind" value="imagery">

                    <div class="mb-3">
                        <label for="imageryName" class="form-label">Layer name</label>
                        <input type="text" name="name" id="imageryName" value="{{ old('name') }}" class="form-control" maxlength="255" placeholder="Coast, September 2026">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="acquiredAt" class="form-label">Captured on</label>
                            <input type="date" name="acquired_at" id="acquiredAt" value="{{ old('acquired_at', now()->toDateString()) }}" class="form-control" required>
                            <p class="small mb-0 mt-1" style="color:#3f4c5e;">This date decides which scene is drawn where two images overlap.</p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="layerId" class="form-label">Add to an imagery layer</label>
                            <select name="layer_id" id="layerId" class="form-select">
                                <option value="">New imagery layer</option>
                                @foreach($imageryLayers as $imageryLayer)
                                    <option value="{{ $imageryLayer->id }}" @selected((string) old('layer_id') === (string) $imageryLayer->id)>{{ $imageryLayer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="drop-zone" id="imageryDrop">
                        <div class="drop-zone-content" id="imageryDropContent">
                            <p class="mb-1 fw-semibold">Drop a georeferenced image</p>
                            <p class="small mb-3" style="color:#3f4c5e;">
                                GeoTIFF, a zip of a GeoTIFF, or JPEG/PNG together with the matching world file and .prj.
                                Up to {{ (int) round(config('imagery.max_file_size') / 1048576) }} MB.
                            </p>
                            <label for="imageryFiles" class="btn btn-primary">Choose files</label>
                            <input type="file"
                                   name="files[]"
                                   id="imageryFiles"
                                   class="d-none"
                                   accept=".tif,.tiff,.jpg,.jpeg,.png,.zip,.jgw,.jpgw,.pgw,.pngw,.wld,.tfw,.prj"
                                   multiple
                                   required>
                        </div>
                        <div id="imageryInfo" class="file-info d-none">
                            <p class="mb-1 fw-semibold" id="imageryNameList"></p>
                            <p class="small mb-2" style="color:#3f4c5e;" id="imagerySize"></p>
                            <p class="small mb-0" id="imageryKind"></p>
                            <button type="button" class="btn btn-link btn-sm px-0" id="clearImagery">Choose a different image</button>
                        </div>
                    </div>

                    <p class="small mt-3 mb-4" style="color:#3f4c5e;">
                        A photo without a world file is rejected. Outside this scene’s footprint, the satellite basemap is unchanged.
                    </p>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('imports.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" id="imagerySubmit" disabled>Publish imagery</button>
                    </div>
                </form>
            @else
                <form action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data" id="uploadForm">
                    @csrf

                    <div class="drop-zone" id="dropZone">
                        <div class="drop-zone-content">
                            <p class="mb-1 fw-semibold">Drop a package here</p>
                            <p class="small mb-3" style="color:#3f4c5e;">
                                Shapefile as a .zip (shp, shx, dbf, prj), or the parts together. Also KML, KMZ, GeoJSON, CSV, Excel.
                                Up to {{ (int) round(config('dataimport.max_file_size') / 1048576) }} MB.
                            </p>
                            <label for="files" class="btn btn-primary">Choose files</label>
                            <input type="file"
                                   name="files[]"
                                   id="files"
                                   class="d-none"
                                   accept=".zip,.shp,.shx,.dbf,.prj,.cpg,.geojson,.json,.kml,.kmz,.csv,.xlsx,.xls"
                                   multiple
                                   required>
                        </div>
                        <div id="fileInfo" class="file-info d-none">
                            <p class="mb-1 fw-semibold" id="fileName"></p>
                            <p class="small mb-2" style="color:#3f4c5e;" id="fileSize"></p>
                            <p class="small mb-0" id="fileKind"></p>
                            <button type="button" class="btn btn-link btn-sm px-0" id="clearFiles">Choose a different package</button>
                        </div>
                    </div>

                    <p class="small mt-3 mb-4" style="color:#3f4c5e;">
                        Large packages return immediately and publish in the background. You stay on the import status page until the layer is ready.
                    </p>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('imports.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Publish layer</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

<style>
.drop-zone {
    border: 1px dashed #94a3b8;
    border-radius: 0.85rem;
    padding: 2.5rem 1.5rem;
    text-align: center;
    background: #fff;
    min-height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.drop-zone.drag-over {
    border-color: #0f766e;
    background: #f3faf8;
}
.drop-zone:focus-within {
    outline: 2px solid #0f766e;
    outline-offset: 2px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function bindDrop(dropZone, fileInput, content, info, nameEl, sizeEl, kindEl, submitBtn, clearBtn, kindLabel) {
        if (!dropZone || !fileInput) return;

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (name) {
            dropZone.addEventListener(name, function (event) {
                event.preventDefault();
                event.stopPropagation();
            });
        });
        ['dragenter', 'dragover'].forEach(function (name) {
            dropZone.addEventListener(name, function () { dropZone.classList.add('drag-over'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            dropZone.addEventListener(name, function () { dropZone.classList.remove('drag-over'); });
        });

        dropZone.addEventListener('drop', function (event) {
            const files = event.dataTransfer.files;
            if (!files.length) return;
            const transfer = new DataTransfer();
            Array.from(files).forEach(function (file) { transfer.items.add(file); });
            fileInput.files = transfer.files;
            describe(fileInput.files);
        });

        fileInput.addEventListener('change', function () {
            describe(this.files);
        });

        clearBtn.addEventListener('click', function () {
            fileInput.value = '';
            content.classList.remove('d-none');
            info.classList.add('d-none');
            submitBtn.disabled = true;
        });

        function describe(files) {
            if (!files.length) return;
            const names = Array.from(files).map(function (file) { return file.name; });
            const bytes = Array.from(files).reduce(function (sum, file) { return sum + file.size; }, 0);
            const extensions = names.map(function (name) {
                return name.split('.').pop().toLowerCase();
            });

            nameEl.textContent = names.join(', ');
            sizeEl.textContent = formatFileSize(bytes);
            kindEl.textContent = kindLabel(extensions, names);
            content.classList.add('d-none');
            info.classList.remove('d-none');
            submitBtn.disabled = false;
        }
    }

    bindDrop(
        document.getElementById('dropZone'),
        document.getElementById('files'),
        document.querySelector('#dropZone .drop-zone-content'),
        document.getElementById('fileInfo'),
        document.getElementById('fileName'),
        document.getElementById('fileSize'),
        document.getElementById('fileKind'),
        document.getElementById('submitBtn'),
        document.getElementById('clearFiles'),
        function (extensions) {
            if (extensions.includes('zip') || extensions.includes('shp')) {
                return 'Shapefile package. It will publish as one feature layer.';
            }
            if (extensions.includes('kml') || extensions.includes('kmz')) {
                return 'KML dataset. It will publish as one feature layer.';
            }
            if (extensions.includes('geojson') || extensions.includes('json')) {
                return 'GeoJSON dataset. It will publish as one feature layer.';
            }
            if (extensions.includes('csv') || extensions.includes('xlsx') || extensions.includes('xls')) {
                return 'Table with coordinates. It will publish as a point layer.';
            }
            return 'Check the file type. Use a zipped shapefile, KML, GeoJSON, CSV, or Excel file.';
        }
    );

    bindDrop(
        document.getElementById('imageryDrop'),
        document.getElementById('imageryFiles'),
        document.getElementById('imageryDropContent'),
        document.getElementById('imageryInfo'),
        document.getElementById('imageryNameList'),
        document.getElementById('imagerySize'),
        document.getElementById('imageryKind'),
        document.getElementById('imagerySubmit'),
        document.getElementById('clearImagery'),
        function (extensions) {
            if (extensions.includes('tif') || extensions.includes('tiff') || extensions.includes('zip')) {
                return 'Georeferenced raster. It will publish as imagery above the satellite basemap.';
            }
            if (extensions.includes('jpg') || extensions.includes('jpeg') || extensions.includes('png')) {
                const hasWorld = extensions.some(function (ext) {
                    return ['jgw', 'jpgw', 'pgw', 'pngw', 'wld', 'tfw'].includes(ext);
                });
                const hasPrj = extensions.includes('prj');
                if (!hasWorld || !hasPrj) {
                    return 'This image still needs its world file and .prj, or use a GeoTIFF.';
                }
                return 'Georeferenced image. It will publish as imagery above the satellite basemap.';
            }
            return 'Use a GeoTIFF, or a JPEG/PNG with its world file and .prj.';
        }
    );
});
</script>
@endsection
