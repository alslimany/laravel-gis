@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Organization Settings</span>
                    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-secondary">Back to Dashboard</a>
                </div>

                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('organization.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Organization Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name', $organization->name) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror"
                                      id="description" name="description" rows="4">{{ old('description', $organization->description) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="primary_color" class="form-label">Primary color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color"
                                       value="{{ old('primary_color', $organization->primary_color ?: '#0d6efd') }}"
                                       oninput="document.getElementById('primary_color').value = this.value">
                                <input type="text" class="form-control @error('primary_color') is-invalid @enderror"
                                       id="primary_color" name="primary_color"
                                       value="{{ old('primary_color', $organization->primary_color) }}"
                                       placeholder="#0d6efd" pattern="#?[0-9A-Fa-f]{3,8}">
                            </div>
                            @error('primary_color')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="logo" class="form-label">Logo</label>
                            @if($organization->logo_path)
                                <div class="mb-2">
                                    <img src="{{ asset('storage/'.$organization->logo_path) }}"
                                         alt="Organization logo" style="max-height: 64px;">
                                </div>
                            @endif
                            <input type="file" class="form-control @error('logo') is-invalid @enderror"
                                   id="logo" name="logo" accept="image/*">
                            @error('logo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">PNG, JPG, GIF, WebP or SVG up to 2 MB.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Owner</label>
                            <input type="text" class="form-control"
                                   value="{{ $organization->user->name }} ({{ $organization->user->email }})"
                                   readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Members</label>
                            <p class="form-text">{{ $organization->members->count() }} member(s)</p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Projects</label>
                            <p class="form-text">{{ $organization->projects->count() }} project(s)</p>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Update Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
