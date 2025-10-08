@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Edit User</span>
                    <a href="{{ route('users.index') }}" class="btn btn-sm btn-secondary">Back to Users</a>
                </div>

                <div class="card-body">
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('users.update', $user) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" 
                                   id="name" name="name" value="{{ old('name', $user->name) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror" 
                                   id="email" name="email" value="{{ old('email', $user->email) }}" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Roles</label>
                            @foreach($roles as $role)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           name="roles[]" value="{{ $role->id }}" 
                                           id="role-{{ $role->id }}"
                                           {{ $user->roles->contains($role->id) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="role-{{ $role->id }}">
                                        {{ ucfirst($role->name) }}
                                        @if($role->description)
                                            <small class="text-muted">- {{ $role->description }}</small>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Organization</label>
                            <input type="text" class="form-control" 
                                   value="{{ $user->organization ? $user->organization->name : 'None' }}" 
                                   readonly>
                            <small class="form-text text-muted">Contact an administrator to change organization membership.</small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Update User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
