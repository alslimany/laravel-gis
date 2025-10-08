@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Import Details</span>
                    <a href="{{ route('imports.index') }}" class="btn btn-sm btn-secondary">
                        Back to Imports
                    </a>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    <!-- Status Alert -->
                    @if($import->status === 'completed')
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <strong>Import Completed Successfully!</strong>
                        </div>
                    @elseif($import->status === 'processing')
                        <div class="alert alert-primary">
                            <i class="fas fa-spinner fa-spin"></i>
                            <strong>Import In Progress...</strong> Please wait while your file is being processed.
                        </div>
                    @elseif($import->status === 'failed')
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Import Failed</strong>
                            @if($import->error_message)
                                <br>
                                <small>{{ $import->error_message }}</small>
                            @endif
                        </div>
                    @else
                        <div class="alert alert-secondary">
                            <i class="fas fa-clock"></i>
                            <strong>Import Pending</strong> Your file is queued for processing.
                        </div>
                    @endif

                    <!-- Progress Bar -->
                    @if($import->status === 'processing' || $import->status === 'pending')
                        <div class="mb-4">
                            <label class="form-label fw-bold">Processing Progress</label>
                            <div class="progress" style="height: 30px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" 
                                     id="progressBar"
                                     style="width: {{ $import->progress }}%"
                                     aria-valuenow="{{ $import->progress }}" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <span id="progressText">{{ $import->progress }}%</span>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3">File Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 40%;">File Name</th>
                                    <td>{{ $import->file_name }}</td>
                                </tr>
                                <tr>
                                    <th>File Type</th>
                                    <td><span class="badge bg-secondary">{{ strtoupper($import->file_type) }}</span></td>
                                </tr>
                                <tr>
                                    <th>File Size</th>
                                    <td>{{ number_format($import->file_size / 1024, 2) }} KB</td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td id="statusBadge">
                                        @if($import->status === 'completed')
                                            <span class="badge bg-success">Completed</span>
                                        @elseif($import->status === 'processing')
                                            <span class="badge bg-primary">Processing</span>
                                        @elseif($import->status === 'failed')
                                            <span class="badge bg-danger">Failed</span>
                                        @else
                                            <span class="badge bg-secondary">Pending</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Uploaded By</th>
                                    <td>{{ $import->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>Uploaded At</th>
                                    <td>{{ $import->created_at->format('Y-m-d H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5 class="mb-3">Import Results</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 40%;">Table Name</th>
                                    <td id="tableName">{{ $import->table_name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Geometry Type</th>
                                    <td id="geometryType">{{ $import->geometry_type ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Feature Count</th>
                                    <td id="featureCount">
                                        @if($import->feature_count)
                                            {{ number_format($import->feature_count) }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Started At</th>
                                    <td>{{ $import->started_at ? $import->started_at->format('Y-m-d H:i:s') : 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Completed At</th>
                                    <td>{{ $import->completed_at ? $import->completed_at->format('Y-m-d H:i:s') : 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Duration</th>
                                    <td>
                                        @if($import->started_at && $import->completed_at)
                                            {{ $import->started_at->diffForHumans($import->completed_at, true) }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    @if($import->metadata)
                        <div class="mt-4">
                            <h5 class="mb-3">Additional Metadata</h5>
                            <pre class="bg-light p-3 rounded">{{ json_encode($import->metadata, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    @endif

                    @if($import->status === 'completed')
                        <div class="mt-4">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Next Steps:</strong>
                                <ul class="mb-0">
                                    <li>The data is now available in PostGIS table: <code>{{ $import->table_name }}</code></li>
                                    <li>Create a layer from this import to visualize and manage the data</li>
                                    <li>Once created, you can publish the layer to GeoServer for visualization</li>
                                </ul>
                            </div>
                            <a href="{{ route('layers.create', ['table_name' => $import->table_name, 'name' => $import->file_name]) }}" class="btn btn-primary">
                                <i class="fas fa-layer-group"></i> Create Layer from Import
                            </a>
                        </div>
                    @endif

                    @if($import->status !== 'processing' && $import->status !== 'pending')
                        <div class="mt-4">
                            <form action="{{ route('imports.destroy', $import) }}" 
                                  method="POST" 
                                  onsubmit="return confirm('Are you sure you want to delete this import? This will not delete the imported data table.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-trash"></i> Delete Import Record
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@if($import->status === 'processing' || $import->status === 'pending')
<script>
// Auto-refresh status every 3 seconds
setInterval(function() {
    fetch('{{ route('imports.status', $import) }}')
        .then(response => response.json())
        .then(data => {
            // Update progress bar
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            if (progressBar && progressText) {
                progressBar.style.width = data.progress + '%';
                progressBar.setAttribute('aria-valuenow', data.progress);
                progressText.textContent = data.progress + '%';
            }

            // Update status badge
            const statusBadge = document.getElementById('statusBadge');
            if (statusBadge && data.status !== '{{ $import->status }}') {
                // Reload page if status changed
                location.reload();
            }

            // Update result fields if available
            if (data.table_name) {
                document.getElementById('tableName').textContent = data.table_name;
            }
            if (data.geometry_type) {
                document.getElementById('geometryType').textContent = data.geometry_type;
            }
            if (data.feature_count) {
                document.getElementById('featureCount').textContent = data.feature_count.toLocaleString();
            }
        })
        .catch(error => {
            console.error('Error fetching status:', error);
        });
}, 3000);
</script>
@endif
@endsection
