@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Edit Layer: {{ $layer->name }}</div>

                <div class="card-body">
                    <form method="POST" action="{{ route('layers.update', $layer) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Layer Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" 
                                   id="name" name="name" value="{{ old('name', $layer->name) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" 
                                      id="description" name="description" rows="3">{{ old('description', $layer->description) }}</textarea>
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
                                    <option value="{{ $project->id }}" {{ old('project_id', $layer->project_id) == $project->id ? 'selected' : '' }}>
                                        {{ $project->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('project_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Table Name</label>
                            <input type="text" class="form-control" value="{{ $layer->table_name }}" disabled>
                            <small class="form-text text-muted">
                                Table name cannot be changed after creation.
                            </small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Geometry Type</label>
                            <input type="text" class="form-control" value="{{ $layer->geometry_type ?? 'Not detected' }}" disabled>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('layers.show', $layer) }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Layer</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Style Editor Section -->
            <div class="card mt-3">
                <div class="card-header">Style Configuration</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('layers.style.update', $layer) }}" id="styleForm">
                        @csrf

                        <div class="mb-3">
                            <label for="fillColor" class="form-label">Fill Color</label>
                            <input type="color" class="form-control form-control-color" 
                                   id="fillColor" name="style_config[fillColor]" 
                                   value="{{ $layer->style_config['fillColor'] ?? '#AAAAAA' }}">
                        </div>

                        <div class="mb-3">
                            <label for="strokeColor" class="form-label">Stroke Color</label>
                            <input type="color" class="form-control form-control-color" 
                                   id="strokeColor" name="style_config[strokeColor]" 
                                   value="{{ $layer->style_config['strokeColor'] ?? '#000000' }}">
                        </div>

                        <div class="mb-3">
                            <label for="strokeWidth" class="form-label">Stroke Width</label>
                            <input type="number" class="form-control" 
                                   id="strokeWidth" name="style_config[strokeWidth]" 
                                   value="{{ $layer->style_config['strokeWidth'] ?? 1 }}" min="1" max="10">
                        </div>

                        <div class="mb-3">
                            <label for="fillOpacity" class="form-label">Fill Opacity</label>
                            <input type="number" class="form-control" 
                                   id="fillOpacity" name="style_config[fillOpacity]" 
                                   value="{{ $layer->style_config['fillOpacity'] ?? 0.5 }}" 
                                   min="0" max="1" step="0.1">
                        </div>

                        <button type="submit" class="btn btn-primary">Update Style</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
