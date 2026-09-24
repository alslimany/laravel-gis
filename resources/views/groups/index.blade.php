@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Groups</span>
                    <div>
                        @can('create', App\Models\Group::class)
                            <a href="{{ route('groups.create') }}" class="btn btn-primary btn-sm">New Group</a>
                        @endcan
                        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-secondary">Back</a>
                    </div>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Members</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($groups as $group)
                                    <tr>
                                        <td>{{ $group->name }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($group->description, 80) }}</td>
                                        <td>{{ $group->users_count }}</td>
                                        <td>
                                            <a href="{{ route('groups.show', $group) }}" class="btn btn-sm btn-outline-primary">View</a>
                                            @can('update', $group)
                                                <a href="{{ route('groups.edit', $group) }}" class="btn btn-sm btn-primary">Edit</a>
                                            @endcan
                                            @can('delete', $group)
                                                <form action="{{ route('groups.destroy', $group) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this group?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No groups yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-center">
                        {{ $groups->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
