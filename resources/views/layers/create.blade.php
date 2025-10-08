@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Create New Layer</div>

                <div class="card-body">
                    <form method="POST" action="{{ route('layers.store') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="name" class="form-label">Layer Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" 
                                   id="name" name="name" value="{{ old('name', request('name')) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" 
                                      id="description" name="description" rows="3">{{ old('description') }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="project_id" class="form-label">Project</label>
                            <select class="form-select @error('project_id') is-invalid @enderror" 
                                    id="project_id" name="project_id">
                                <option value="">-- No Project --</option>
                                @foreach($projects as $project)
                                    <option value="{{ $project->id }}" {{ old('project_id') == $project->id ? 'selected' : '' }}>
                                        {{ $project->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('project_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="table_name" class="form-label">Table Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('table_name') is-invalid @enderror" 
                                   id="table_name" name="table_name" value="{{ old('table_name', request('table_name')) }}" required>
                            <small class="form-text text-muted">
                                The PostGIS table name that contains the layer data (e.g., from a data import).
                            </small>
                            @error('table_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="geometry_type" class="form-label">Geometry Type</label>
                            <select class="form-select @error('geometry_type') is-invalid @enderror" 
                                    id="geometry_type" name="geometry_type">
                                <option value="">-- Auto Detect --</option>
                                <option value="Point" {{ old('geometry_type') == 'Point' ? 'selected' : '' }}>Point</option>
                                <option value="LineString" {{ old('geometry_type') == 'LineString' ? 'selected' : '' }}>LineString</option>
                                <option value="Polygon" {{ old('geometry_type') == 'Polygon' ? 'selected' : '' }}>Polygon</option>
                                <option value="MultiPoint" {{ old('geometry_type') == 'MultiPoint' ? 'selected' : '' }}>MultiPoint</option>
                                <option value="MultiLineString" {{ old('geometry_type') == 'MultiLineString' ? 'selected' : '' }}>MultiLineString</option>
                                <option value="MultiPolygon" {{ old('geometry_type') == 'MultiPolygon' ? 'selected' : '' }}>MultiPolygon</option>
                            </select>
                            @error('geometry_type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('layers.index') }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Create Layer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
