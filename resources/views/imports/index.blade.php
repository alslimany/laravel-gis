@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Data Imports</span>
                    <a href="{{ route('imports.create') }}" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload"></i> New Import
                    </a>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if($imports->isEmpty())
                        <div class="text-center py-5">
                            <p class="text-muted">No imports yet.</p>
                            <a href="{{ route('imports.create') }}" class="btn btn-primary">
                                Upload Your First File
                            </a>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Features</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($imports as $import)
                                        <tr>
                                            <td>
                                                <a href="{{ route('imports.show', $import) }}">
                                                    {{ $import->file_name }}
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">{{ strtoupper($import->file_type) }}</span>
                                            </td>
                                            <td>{{ number_format($import->file_size / 1024, 2) }} KB</td>
                                            <td>
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
                                            <td>
                                                <div class="progress" style="width: 100px;">
                                                    <div class="progress-bar" role="progressbar" 
                                                         style="width: {{ $import->progress }}%"
                                                         aria-valuenow="{{ $import->progress }}" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                        {{ $import->progress }}%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                @if($import->feature_count)
                                                    {{ number_format($import->feature_count) }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td>{{ $import->created_at->diffForHumans() }}</td>
                                            <td>
                                                <a href="{{ route('imports.show', $import) }}" 
                                                   class="btn btn-sm btn-info">
                                                    View
                                                </a>
                                                @if($import->status === 'failed' || $import->status === 'completed')
                                                    <form action="{{ route('imports.destroy', $import) }}" 
                                                          method="POST" 
                                                          class="d-inline"
                                                          onsubmit="return confirm('Are you sure you want to delete this import?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            Delete
                                                        </button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $imports->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
