@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Edit Project</div>

                <div class="card-body">
                    <form method="POST" action="{{ route('projects.update', $project) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Project Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control @error('name') is-invalid @enderror" 
                                   id="name" 
                                   name="name" 
                                   value="{{ old('name', $project->name) }}" 
                                   required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" 
                                      id="description" 
                                      name="description" 
                                      rows="4">{{ old('description', $project->description) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" 
                                       type="checkbox" 
                                       id="is_public" 
                                       name="is_public" 
                                       value="1"
                                       {{ old('is_public', $project->is_public) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_public">
                                    Make this project public
                                </label>
                            </div>
                            <small class="text-muted">Public projects can be viewed by anyone with the share link</small>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('projects.show', $project) }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Project</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
