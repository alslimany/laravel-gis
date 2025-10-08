@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header">
                    Upload Spatial Data File
                </div>

                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('imports.store') }}" 
                          method="POST" 
                          enctype="multipart/form-data"
                          id="uploadForm">
                        @csrf

                        <div class="mb-4">
                            <label class="form-label fw-bold">Supported File Formats</label>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="fas fa-map fa-2x text-primary mb-2"></i>
                                            <h6>Shapefile</h6>
                                            <small class="text-muted">.shp + .shx, .dbf, .prj</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="fas fa-code fa-2x text-success mb-2"></i>
                                            <h6>GeoJSON</h6>
                                            <small class="text-muted">.geojson, .json</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="fas fa-map-marker-alt fa-2x text-danger mb-2"></i>
                                            <h6>KML</h6>
                                            <small class="text-muted">.kml, .kmz</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <i class="fas fa-table fa-2x text-warning mb-2"></i>
                                            <h6>CSV</h6>
                                            <small class="text-muted">.csv with coordinates</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="file" class="form-label fw-bold">
                                Main File <span class="text-danger">*</span>
                            </label>
                            <div class="drop-zone" id="dropZone">
                                <div class="drop-zone-content">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <p class="mb-2">Drag & Drop your file here</p>
                                    <p class="text-muted small">or</p>
                                    <label for="file" class="btn btn-primary">
                                        Browse Files
                                    </label>
                                    <input type="file" 
                                           name="file" 
                                           id="file" 
                                           class="d-none"
                                           accept=".shp,.shx,.dbf,.prj,.cpg,.geojson,.json,.kml,.kmz,.csv"
                                           required>
                                </div>
                                <div id="fileInfo" class="file-info d-none">
                                    <i class="fas fa-file fa-2x text-primary mb-2"></i>
                                    <p class="mb-0 fw-bold" id="fileName"></p>
                                    <p class="text-muted small" id="fileSize"></p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4" id="additionalFilesSection" style="display: none;">
                            <label for="additional_files" class="form-label fw-bold">
                                Additional Files
                                <small class="text-muted">(Required for Shapefiles: .shx, .dbf, .prj)</small>
                            </label>
                            <input type="file" 
                                   name="additional_files[]" 
                                   id="additional_files" 
                                   class="form-control"
                                   accept=".shx,.dbf,.prj,.cpg"
                                   multiple>
                            <div class="form-text">
                                Select all associated files (.shx, .dbf, .prj, .cpg)
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> 
                            Large files will be processed in the background. You can monitor the progress on the imports page.
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('imports.index') }}" class="btn btn-secondary">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-upload"></i> Upload File
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.drop-zone {
    border: 3px dashed #dee2e6;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drop-zone:hover {
    border-color: #0d6efd;
    background-color: #f8f9fa;
}

.drop-zone.drag-over {
    border-color: #0d6efd;
    background-color: #e7f1ff;
}

.file-info {
    text-align: center;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('file');
    const additionalFilesSection = document.getElementById('additionalFilesSection');
    const fileInfo = document.getElementById('fileInfo');
    const dropZoneContent = document.querySelector('.drop-zone-content');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');

    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    // Highlight drop zone when item is dragged over it
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.add('drag-over');
        });
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.remove('drag-over');
        });
    });

    // Handle dropped files
    dropZone.addEventListener('drop', function(e) {
        const dt = e.dataTransfer;
        const files = dt.files;

        if (files.length > 0) {
            fileInput.files = files;
            handleFile(files[0]);
        }
    });

    // Handle file selection
    fileInput.addEventListener('change', function(e) {
        if (this.files.length > 0) {
            handleFile(this.files[0]);
        }
    });

    // Click on drop zone to trigger file input
    dropZone.addEventListener('click', function(e) {
        if (e.target !== fileInput && !e.target.closest('label[for="file"]')) {
            fileInput.click();
        }
    });

    function handleFile(file) {
        // Display file info
        fileName.textContent = file.name;
        fileSize.textContent = formatFileSize(file.size);
        dropZoneContent.classList.add('d-none');
        fileInfo.classList.remove('d-none');

        // Check if shapefile
        const extension = file.name.split('.').pop().toLowerCase();
        if (extension === 'shp') {
            additionalFilesSection.style.display = 'block';
        } else {
            additionalFilesSection.style.display = 'none';
        }
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }
});
</script>
@endsection
