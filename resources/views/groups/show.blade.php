@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ $group->name }}</span>
                    <div>
                        @can('update', $group)
                            <a href="{{ route('groups.edit', $group) }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        <a href="{{ route('groups.index') }}" class="btn btn-sm btn-secondary">Back</a>
                    </div>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <p class="text-muted mb-0">{{ $group->description ?: 'No description.' }}</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Members</div>
                <div class="card-body">
                    @can('manageMembers', $group)
                        <form method="POST" action="{{ route('groups.users.attach', $group) }}" class="row g-2 align-items-end mb-4">
                            @csrf
                            <div class="col-md-6">
                                <label for="user_id" class="form-label">Add member</label>
                                <select name="user_id" id="user_id" class="form-select" required>
                                    <option value="">Select user…</option>
                                    @foreach($availableUsers as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="role" class="form-label">Role</label>
                                <select name="role" id="role" class="form-select" required>
                                    <option value="member">Member</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100" @disabled($availableUsers->isEmpty())>Add</button>
                            </div>
                        </form>
                    @endcan

                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($group->users as $member)
                                    <tr>
                                        <td>{{ $member->name }}</td>
                                        <td>{{ $member->email }}</td>
                                        <td>
                                            @can('manageMembers', $group)
                                                <form method="POST" action="{{ route('groups.users.updateRole', [$group, $member]) }}" class="d-flex gap-2">
                                                    @csrf
                                                    @method('PUT')
                                                    <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                                                        <option value="member" @selected($member->pivot->role === 'member')>Member</option>
                                                        <option value="admin" @selected($member->pivot->role === 'admin')>Admin</option>
                                                    </select>
                                                </form>
                                            @else
                                                <span class="badge bg-secondary">{{ ucfirst($member->pivot->role) }}</span>
                                            @endcan
                                        </td>
                                        <td class="text-end">
                                            @can('manageMembers', $group)
                                                <form method="POST" action="{{ route('groups.users.detach', [$group, $member]) }}" class="d-inline" onsubmit="return confirm('Remove this member?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No members yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
